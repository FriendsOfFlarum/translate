<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Translation;

use Closure;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Translate\Content\ContentProtector;
use FoF\Translate\Detection\Language;
use FoF\Translate\Detection\PlainText;
use FoF\Translate\Detection\PollLanguage;
use FoF\Translate\Driver\BatchTranslationDriver;
use FoF\Translate\Driver\DriverManager;
use FoF\Translate\Driver\TranslationDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use FoF\Translate\Driver\TranslationResult;
use FoF\Translate\Model\AbstractTranslation;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PollOptionTranslation;
use FoF\Translate\Model\PollTranslation;
use FoF\Translate\Model\PostTranslation;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Cache-first on-demand translation. A cached row is served when it is fresh or was
 * edited by a human (never overwritten, not even on refresh); otherwise the source is
 * unparsed, protected, sent to the current driver, restored and upserted.
 *
 * Cost controls on the miss path: a source length cap, a short negative cache after a
 * driver failure, and single-flight locking so concurrent clicks on the same subject
 * and language pay for one provider call.
 */
class TranslationService
{
    public const CONTEXT_EXCERPT_LENGTH = 500;

    /** Seconds a failed source (same subject, language and text) is not re-sent. */
    public const FAILURE_TTL = 120;

    /** Seconds a source the driver reported as already in the target language is not re-sent. */
    public const SAME_LANGUAGE_TTL = 86400;

    protected const MARKER_FAILED = 'failed';
    protected const MARKER_SAME_LANGUAGE = 'same-language';

    /** Seconds a single-flight lock is held at most, and waited for at most. */
    public const LOCK_TTL = 60;
    public const LOCK_WAIT = 30;

    public function __construct(
        protected DriverManager $drivers,
        protected ContentProtector $protector,
        protected Glossary $glossary,
        protected LoggerInterface $logger,
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings,
        protected PollLanguage $pollLanguage,
    ) {
    }

    public function translatePost(CommentPost $post, string $locale, bool $refresh = false): PostTranslation
    {
        $this->assertNotSameLanguage(Language::normalize($post->detected_lang), $locale);

        $find = fn (): ?PostTranslation => PostTranslation::query()->where('post_id', $post->id)->where('language', $locale)->first();

        $row = $find();

        if ($this->isServable($row, $refresh)) {
            return $row;
        }

        $driver = $this->driver();

        /** @var PostTranslation $row */
        $row = $this->singleFlight('post', $post->id, $locale, $row, $find, $refresh, function (?PostTranslation $row) use ($post, $locale, $driver) {
            $post->loadMissing(['discussion.firstPost', 'user']);

            $source = (string) $post->getFormatter()->unparse($post->parsed_content, $post);

            [[$text], $detected] = $this->translateTexts($driver, [$source], $locale, Language::normalize($post->detected_lang), $this->postContext($post), false, 'post.'.$post->id, $post);

            // Always re-parse as the author: driver output is untrusted text, never stored
            // as XML, or a post containing literal s9e markup would render as live HTML.
            $content = $post->getFormatter()->parse($text, $post, $post->user ?? new Guest());

            $row = $this->upsert(PostTranslation::class, $row, ['post_id' => $post->id, 'language' => $locale], [
                'content' => $content,
                'provider' => $driver::name(),
            ]);

            $this->learn($post, $detected, $source);

            return $row;
        });

        $row->setRelation('post', $post);

        return $row;
    }

    public function translateDiscussion(Discussion $discussion, string $locale, bool $refresh = false): DiscussionTranslation
    {
        $this->assertNotSameLanguage(Language::normalize($discussion->detected_lang), $locale);

        $find = fn (): ?DiscussionTranslation => DiscussionTranslation::query()->where('discussion_id', $discussion->id)->where('language', $locale)->first();

        $row = $find();

        if ($this->isServable($row, $refresh)) {
            return $row;
        }

        $driver = $this->driver();

        /** @var DiscussionTranslation $row */
        $row = $this->singleFlight('discussion', $discussion->id, $locale, $row, $find, $refresh, function (?DiscussionTranslation $row) use ($discussion, $locale, $driver) {
            $discussion->loadMissing('firstPost');

            $context = $this->contextablePost($discussion->firstPost) ? $this->excerpt($discussion->firstPost) : null;

            [[$text], $detected] = $this->translateTexts($driver, [(string) $discussion->title], $locale, Language::normalize($discussion->detected_lang), $context, true, 'discussion.'.$discussion->id, $discussion);

            $row = $this->upsert(DiscussionTranslation::class, $row, ['discussion_id' => $discussion->id, 'language' => $locale], [
                'translation' => trim($text),
                'provider' => $driver::name(),
            ]);

            $this->learn($discussion, $detected, (string) $discussion->title);

            return $row;
        });

        $row->setRelation('discussion', $discussion);

        return $row;
    }

    /**
     * Translates the poll strings and every option. Options are cached per row, so a
     * changed answer re-translates only that answer. Batch-capable drivers receive every
     * pending string in one call.
     */
    public function translatePoll(Poll $poll, string $locale, bool $refresh = false): PollTranslation
    {
        // Polls are detected on the fly (never stored): question, subtitle and answers.
        $poll->loadMissing('options');
        $sourceLanguage = $this->pollLanguage->of($poll);

        $this->assertNotSameLanguage($sourceLanguage, $locale);

        $find = fn (): ?PollTranslation => PollTranslation::query()->where('poll_id', $poll->id)->where('language', $locale)->first();

        /** @var Collection<int, PollOption> $options */
        $options = $poll->options;

        $findOptions = fn (): Collection => $options->isEmpty()
            ? new Collection()
            : PollOptionTranslation::query()
                ->whereIn('option_id', $options->modelKeys())
                ->where('language', $locale)
                ->get()
                ->keyBy('option_id');

        $row = $find();
        $optionRows = $findOptions();

        if ($this->pollIsServable($row, $options, $optionRows, $refresh)) {
            return $this->withOptions($row, $options, $optionRows)->setRelation('poll', $poll);
        }

        $driver = $this->driver();

        $row = $this->withLock('poll', $poll->id, $locale, function () use ($poll, $locale, $refresh, $driver, $sourceLanguage, $options, $find, $findOptions, $row) {
            $fresh = $find();
            $optionRows = $findOptions();

            if ($this->pollIsServable($fresh, $options, $optionRows, $refresh)
                || ($refresh && $this->pollIsServable($fresh, $options, $optionRows, false) && $this->changedWhileWaiting($row, $fresh))) {
                return $this->withOptions($fresh, $options, $optionRows);
            }

            $row = $fresh;
            $sources = [];

            if (! $this->isServable($row, $refresh)) {
                foreach (['question', 'subtitle', 'image_alt'] as $column) {
                    $value = trim((string) $poll->{$column});

                    if ($value !== '') {
                        $sources[$column] = $value;
                    }
                }
            }

            foreach ($options as $option) {
                if (! $this->isServable($optionRows->get($option->id), $refresh)) {
                    $sources['option.'.$option->id] = (string) $option->answer;
                }
            }

            [$texts] = $this->translateTexts($driver, array_values($sources), $locale, $sourceLanguage, (string) $poll->question, true, 'poll.'.$poll->id);

            $translated = array_combine(array_keys($sources), array_map('trim', $texts));

            if (! $this->isServable($row, $refresh)) {
                $row = $this->upsert(PollTranslation::class, $row, ['poll_id' => $poll->id, 'language' => $locale], [
                    'question' => $translated['question'] ?? '',
                    'subtitle' => $translated['subtitle'] ?? null,
                    'image_alt' => $translated['image_alt'] ?? null,
                    'provider' => $driver::name(),
                ]);
            }

            $optionValues = [];

            foreach ($options as $option) {
                if (isset($translated['option.'.$option->id])) {
                    $optionValues[] = [
                        'option_id' => $option->id,
                        'language' => $locale,
                        'answer' => $translated['option.'.$option->id],
                        'provider' => $driver::name(),
                        'update_needed' => false,
                    ];
                }
            }

            // One statement for every answer on the unique (option_id, language) key, then
            // one re-read. Edited rows never reach here (they are always servable).
            if ($optionValues) {
                PollOptionTranslation::query()->upsert($optionValues, ['option_id', 'language'], ['answer', 'provider', 'update_needed']);
                $optionRows = $findOptions();
            }

            /** @var PollTranslation $row */
            return $this->withOptions($row, $options, $optionRows);
        });

        $row->setRelation('poll', $poll);

        return $row;
    }

    /**
     * @phpstan-assert-if-true AbstractTranslation $row
     */
    protected function isServable(?AbstractTranslation $row, bool $refresh): bool
    {
        if ($row === null) {
            return false;
        }

        return $row->isEdited() || (! $refresh && ! $row->isStale());
    }

    /**
     * @param Collection<int, PollOption>            $options
     * @param Collection<int, PollOptionTranslation> $optionRows
     */
    protected function pollIsServable(?PollTranslation $row, Collection $options, Collection $optionRows, bool $refresh): bool
    {
        if (! $this->isServable($row, $refresh)) {
            return false;
        }

        foreach ($options as $option) {
            if (! $this->isServable($optionRows->get($option->id), $refresh)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run the miss path once per subject and language at a time. After the lock is
     * acquired the row is re-read: a concurrent request may have produced it meanwhile.
     *
     * @template T of AbstractTranslation
     *
     * @param T|null                 $before row as seen before waiting
     * @param Closure(): (T|null)    $find
     * @param Closure(T|null): T     $translate
     *
     * @return T
     */
    protected function singleFlight(string $type, int $id, string $locale, ?AbstractTranslation $before, Closure $find, bool $refresh, Closure $translate): AbstractTranslation
    {
        return $this->withLock($type, $id, $locale, function () use ($before, $find, $refresh, $translate) {
            $fresh = $find();

            if ($fresh !== null && ($fresh->isEdited() || (! $fresh->isStale() && (! $refresh || $this->changedWhileWaiting($before, $fresh))))) {
                return $fresh;
            }

            return $translate($fresh);
        });
    }

    /**
     * Whether a concurrent request wrote the row while this one waited for the lock, so
     * a refresh does not pay twice for the same click.
     */
    protected function changedWhileWaiting(?AbstractTranslation $before, ?AbstractTranslation $after): bool
    {
        if ($after === null) {
            return false;
        }

        if ($before === null) {
            return true;
        }

        return $after->updated_at?->getTimestamp() !== $before->updated_at?->getTimestamp();
    }

    /**
     * @template R
     *
     * @param Closure(): R $work
     *
     * @return R
     */
    protected function withLock(string $type, int $id, string $locale, Closure $work): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $work();
        }

        try {
            return $store->lock("fof-translate.$type.$id.$locale", self::LOCK_TTL)->block(self::LOCK_WAIT, $work);
        } catch (LockTimeoutException) {
            throw TranslationError::make(TranslationError::FAILED);
        }
    }

    protected function assertNotSameLanguage(?string $sourceLanguage, string $locale): void
    {
        if ($sourceLanguage !== null && $sourceLanguage === TargetLanguages::primary($locale)) {
            throw TranslationError::make(TranslationError::ALREADY_IN_LANGUAGE);
        }
    }

    protected function driver(): TranslationDriver
    {
        return $this->drivers->current() ?? throw TranslationError::make(TranslationError::NO_DRIVER);
    }

    /**
     * Translate one or more source strings for one subject. Batch-capable drivers get
     * every string in one call; others one call per string.
     *
     * When the driver reports that the source already is in the target language, the
     * learned language is stored on $learnInto (when given), nothing is returned for
     * storage, and ALREADY_IN_LANGUAGE is thrown.
     *
     * @param list<string> $sources
     *
     * @return array{0: list<string>, 1: string|null} restored texts in source order, and the
     *                                                 source language the driver reported
     */
    protected function translateTexts(TranslationDriver $driver, array $sources, string $locale, ?string $sourceLanguage, ?string $context, bool $isPlainText, string $subject, ?Model $learnInto = null): array
    {
        if ($sources === []) {
            return [[], null];
        }

        $this->assertWithinLengthLimit($sources);

        // Outcome marker for this exact source: a recent failure, or a source the driver
        // already reported to be in the target language (polls have no column to learn into).
        $markerKey = 'fof-translate.outcome.'.$subject.'.'.$locale.'.'.sha1(serialize($sources));

        match ($this->cache->get($markerKey)) {
            self::MARKER_FAILED => throw TranslationError::make(TranslationError::FAILED),
            self::MARKER_SAME_LANGUAGE => throw TranslationError::make(TranslationError::ALREADY_IN_LANGUAGE),
            default => null,
        };

        $glossary = $this->glossary->terms();
        $protected = array_map(fn (string $source) => $this->protector->protect($source, $glossary), $sources);

        $requests = array_map(fn ($content) => new TranslationRequest(
            text: $content->text,
            targetLanguage: TargetLanguages::primary($locale),
            sourceLanguage: $sourceLanguage,
            context: $context,
            glossary: $glossary,
            isPlainText: $isPlainText,
        ), $protected);

        try {
            if (count($requests) > 1 && $driver instanceof BatchTranslationDriver) {
                $results = array_values($driver->translateBatch($requests));

                if (count($results) !== count($requests)) {
                    throw new TranslationFailedException(sprintf('batch returned %d results for %d requests', count($results), count($requests)));
                }
            } else {
                $results = array_map(fn (TranslationRequest $request) => $driver->translate($request), $requests);
            }
        } catch (TranslationFailedException $e) {
            $this->logger->error(sprintf('[fof/translate] %s failed to translate %s into %s: %s', $driver::name(), $subject, $locale, $e->getMessage()));

            $this->cache->put($markerKey, self::MARKER_FAILED, self::FAILURE_TTL);

            throw TranslationError::make(TranslationError::FAILED);
        }

        $texts = [];
        $detected = null;

        foreach ($results as $i => $result) {
            /** @var TranslationResult $result */
            $texts[] = $this->protector->restore($result->text, $protected[$i]);
            $detected ??= $this->normalizeLanguage($result->detectedSourceLanguage);
        }

        if ($detected !== null && $detected === TargetLanguages::primary($locale)) {
            if ($learnInto !== null) {
                $this->learn($learnInto, $detected, implode("\n", $sources));
            }

            $this->cache->put($markerKey, self::MARKER_SAME_LANGUAGE, self::SAME_LANGUAGE_TTL);

            throw TranslationError::make(TranslationError::ALREADY_IN_LANGUAGE);
        }

        return [$texts, $detected];
    }

    /**
     * @param list<string> $sources
     */
    protected function assertWithinLengthLimit(array $sources): void
    {
        $max = (int) $this->settings->get('fof-translate.max_source_length', 20000);

        if ($max > 0 && array_sum(array_map('mb_strlen', $sources)) > $max) {
            throw TranslationError::make(TranslationError::TOO_LONG);
        }
    }

    /**
     * @template T of AbstractTranslation
     *
     * @param class-string<T>      $class
     * @param T|null               $row
     * @param array<string, mixed> $key
     * @param array<string, mixed> $values
     *
     * @return T
     */
    protected function upsert(string $class, ?AbstractTranslation $row, array $key, array $values): AbstractTranslation
    {
        $values['update_needed'] = false;

        if ($row !== null) {
            $row->forceFill($values)->save();

            return $row;
        }

        try {
            return $class::query()->create($key + $values);
        } catch (UniqueConstraintViolationException) {
            // Another request inserted first (e.g. the cache store has no locks).
            /** @var T $existing */
            $existing = $class::query()->where($key)->firstOrFail();

            if (! $existing->isEdited()) {
                $existing->forceFill($values)->save();
            }

            return $existing;
        }
    }

    /**
     * Learn-on-translate: the driver saw the real source language, so a wrong or
     * missing detection corrects itself. One quiet UPDATE, no model events.
     */
    /**
     * Content below the local detector's minimum length is never learned into: a
     * provider's guess for "+1" or "ok" is no better than "undetermined".
     */
    protected function learn(Model $subject, ?string $detected, string $sourceText): void
    {
        if ($detected === null || $detected === Language::normalize($subject->getAttribute('detected_lang'))) {
            return;
        }

        $minimum = max(1, (int) $this->settings->get('fof-translate.short_content_length', 10));

        if (mb_strlen(PlainText::from($sourceText)) < $minimum) {
            return;
        }

        $subject->newQuery()->whereKey($subject->getKey())->update(['detected_lang' => $detected]);

        $subject->setAttribute('detected_lang', $detected);
        $subject->syncOriginalAttribute('detected_lang');
    }

    protected function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        return Language::normalize(TargetLanguages::primary(trim($language)));
    }

    protected function postContext(CommentPost $post): ?string
    {
        $discussion = $post->discussion;

        if (! $discussion) {
            return null;
        }

        $parts = ['Discussion title: '.$discussion->title];

        $first = $discussion->firstPost;

        if ($this->contextablePost($first) && $first->id !== $post->id) {
            $parts[] = 'Opening post (excerpt): '.$this->excerpt($first);
        }

        return implode("\n", $parts);
    }

    /**
     * Context is sent to a third-party provider, so a hidden or unapproved opening post
     * (which the reader may not be able to see) is never included.
     *
     * @phpstan-assert-if-true CommentPost $post
     */
    protected function contextablePost(mixed $post): bool
    {
        return $post instanceof CommentPost
            && $post->hidden_at === null
            && $post->getAttribute('is_approved') !== false
            && $post->getAttribute('is_approved') !== 0;
    }

    protected function excerpt(CommentPost $post): string
    {
        $text = PlainText::from((string) $post->getFormatter()->unparse($post->parsed_content, $post));

        return mb_strlen($text) > self::CONTEXT_EXCERPT_LENGTH
            ? mb_substr($text, 0, self::CONTEXT_EXCERPT_LENGTH).'…'
            : $text;
    }

    /**
     * @param Collection<int, PollOption>            $options
     * @param Collection<int, PollOptionTranslation> $optionRows
     */
    protected function withOptions(PollTranslation $row, Collection $options, Collection $optionRows): PollTranslation
    {
        $row->optionTranslations = new Collection(
            $options->map(fn (PollOption $option) => $optionRows->get($option->id))->filter()->values()->all()
        );

        return $row;
    }
}
