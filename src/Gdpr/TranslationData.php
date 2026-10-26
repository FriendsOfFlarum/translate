<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Gdpr;

use Flarum\Database\Eloquent\Collection;
use Flarum\Discussion\Discussion;
use Flarum\Gdpr\Data\Type;
use Flarum\Post\CommentPost;
use FoF\Translate\Detection\Language;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PostTranslation;
use Illuminate\Database\Query\Builder;

/**
 * Translations are derived from a user's content, so they belong in a right-of-access
 * export. Erasure needs nothing here for posts and discussions: their translation rows
 * cascade with them through foreign keys.
 */
class TranslationData extends Type
{
    public function export(): ?array
    {
        $files = [];

        // The same posts core's Posts type exports: the user's visible, non-private comments.
        CommentPost::query()
            ->where('user_id', $this->user->id)
            ->where('type', CommentPost::$type)
            ->where('is_private', false)
            ->whereVisibleTo($this->user)
            ->whereIn('id', PostTranslation::query()->select('post_id'))
            ->orderBy('id')
            ->chunk(100, function (Collection $posts) use (&$files) {
                $translations = PostTranslation::query()->whereIn('post_id', $posts->modelKeys())->orderBy('language')->get()->groupBy('post_id');

                foreach ($posts as $post) {
                    /** @var CommentPost $post */
                    $files[] = ["translations/post-{$post->id}.json" => $this->encodeForExport([
                        'post_id' => $post->id,
                        'discussion_id' => $post->discussion_id,
                        'detected_language' => Language::normalize($post->detected_lang),
                        'translations' => $translations->get($post->id, new Collection())->map(fn (PostTranslation $translation) => [
                            'language' => $translation->language,
                            'content' => $translation->content ? $post->getFormatter()->unparse($translation->content, $post) : null,
                            'driver' => $translation->provider,
                            'updated_at' => $translation->updated_at?->toIso8601String(),
                        ])->values()->all(),
                    ])];
                }
            });

        Discussion::query()
            ->where('user_id', $this->user->id)
            ->whereVisibleTo($this->user)
            ->whereIn('id', DiscussionTranslation::query()->select('discussion_id'))
            ->orderBy('id')
            ->chunk(100, function (Collection $discussions) use (&$files) {
                $translations = DiscussionTranslation::query()->whereIn('discussion_id', $discussions->modelKeys())->orderBy('language')->get()->groupBy('discussion_id');

                foreach ($discussions as $discussion) {
                    /** @var Discussion $discussion */
                    $files[] = ["translations/discussion-{$discussion->id}.json" => $this->encodeForExport([
                        'discussion_id' => $discussion->id,
                        'detected_language' => Language::normalize($discussion->detected_lang),
                        'translations' => $translations->get($discussion->id, new Collection())->map(fn (DiscussionTranslation $translation) => [
                            'language' => $translation->language,
                            'title' => $translation->translation,
                            'driver' => $translation->provider,
                            'updated_at' => $translation->updated_at?->toIso8601String(),
                        ])->values()->all(),
                    ])];
                }
            });

        return $files ?: null;
    }

    public function anonymize(): void
    {
        // Anonymization keeps the content, so the translations of that content stay too.
    }

    /**
     * Runs after core's Posts type has deleted the user's posts (extension types are
     * processed after core's). Removes every translation whose source no longer exists,
     * rather than trusting foreign keys to cascade: poll tables only get them when
     * fof/polls was installed first, and SQLite enforces them only with the pragma on.
     */
    public function delete(): void
    {
        $connection = PostTranslation::query()->getConnection();
        $schema = $connection->getSchemaBuilder();

        foreach (self::SOURCES as $translations => [$sources, $key]) {
            // Our translation tables, posts and discussions always exist; only fof/polls' tables may not.
            if (str_starts_with($sources, 'poll') && ! $schema->hasTable($sources)) {
                continue;
            }

            $connection->table($translations)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from($sources)->whereColumn("$sources.id", "$translations.$key"))
                ->delete();
        }
    }

    /** Translation table => [source table, foreign key column]. */
    protected const SOURCES = [
        'post_translations' => ['posts', 'post_id'],
        'discussion_translations' => ['discussions', 'discussion_id'],
        'poll_translations' => ['polls', 'poll_id'],
        'poll_option_translations' => ['poll_options', 'option_id'],
    ];
}
