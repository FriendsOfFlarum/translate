<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Api;

use Flarum\Api\Context;
use Flarum\Api\Endpoint\Endpoint;
use Flarum\Api\Resource\AbstractResource;
use Flarum\Post\CommentPost;
use FoF\Polls\Poll;
use FoF\Translate\Driver\DriverManager;
use FoF\Translate\Event\TranslationRefreshed;
use FoF\Translate\Translation\TargetLanguages;
use FoF\Translate\Translation\TranslationError;
use FoF\Translate\Translation\TranslationService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Tobyz\JsonApiServer\Exception\ResourceNotFoundException;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * `POST /{type}/{id}/translate`, mounted on the subject's own resource so the subject is
 * resolved through that resource's visibility scope (hidden => 404). POST, not GET: a
 * call can cost money, so it must not be triggerable cross-site or by prefetchers.
 *
 * Body: {"data": {"attributes": {"locale": "de", "refresh": false}}}
 */
abstract class TranslateEndpoint
{
    public const NAME = 'translate';

    /** Route names, as built by core: "{type}.{endpoint}". */
    public const ROUTES = ['posts.translate', 'discussions.translate', 'polls.translate'];

    public static function post(): Endpoint
    {
        return static::make(function (Context $context, TranslationService $service, string $locale, bool $refresh) {
            $post = $context->model;

            if (! $post instanceof CommentPost) {
                throw TranslationError::make(TranslationError::NOT_TRANSLATABLE);
            }

            static::assertSourceFieldVisible($context, 'contentHtml');

            return $service->translatePost($post, $locale, $refresh);
        }, 'post-translations');
    }

    public static function discussion(): Endpoint
    {
        return static::make(function (Context $context, TranslationService $service, string $locale, bool $refresh) {
            static::assertSourceFieldVisible($context, 'title');

            return $service->translateDiscussion($context->model, $locale, $refresh);
        }, 'discussion-translations');
    }

    public static function poll(): Endpoint
    {
        return static::make(function (Context $context, TranslationService $service, string $locale, bool $refresh) {
            /** @var Poll $poll */
            $poll = $context->model;

            static::assertSourceFieldVisible($context, 'question');

            return $service->translatePoll($poll, $locale, $refresh);
        }, 'poll-translations', ['options' => []]);
    }

    /**
     * @param callable(Context, TranslationService, string, bool): object $translate
     * @param array<string, array<string, mixed>> $include
     */
    protected static function make(callable $translate, string $type, array $include = []): Endpoint
    {
        return Endpoint::make(self::NAME)
            ->route('POST', '/{id}/translate')
            ->action(function (Context $context) use ($translate) {
                $actor = $context->getActor();

                // Global check (not against the subject), matching the forum's canTranslate flag.
                $actor->assertCan('fof-translate.translate');

                if (! resolve(DriverManager::class)->status()['configured']) {
                    throw TranslationError::make(TranslationError::NO_DRIVER);
                }

                $attributes = (array) Arr::get($context->body(), 'data.attributes', []);
                $locale = $attributes['locale'] ?? null;

                // Resolving: may ask the provider once for its real list (then cached).
                $targets = resolve(TargetLanguages::class)->forActor($actor, $context->request->getAttribute('locale'), resolve: true);

                if (! is_string($locale) || ! in_array($locale, $targets, true)) {
                    throw TranslationError::make(TranslationError::UNSUPPORTED_LOCALE);
                }

                // Silently ignored without permission: the cached row is served instead.
                $refresh = ! empty($attributes['refresh']) && $actor->can('fof-translate.refresh');

                $translation = $translate($context, resolve(TranslationService::class), $locale, $refresh);

                if ($refresh) {
                    resolve(Dispatcher::class)->dispatch(new TranslationRefreshed($context->model, $locale, $actor));
                }

                return $translation;
            })
            ->response(fn (Context $context, object $translation) => JsonApiResponse::one($context, $type, $translation, $include));
    }

    /**
     * A translation is exactly as visible as the original text. The subject already passed
     * its resource's visibility scope; this applies the field's own visibility too, as
     * another extension may restrict the field rather than the model. A missing or hidden
     * field answers 404, the same as a hidden subject.
     */
    protected static function assertSourceFieldVisible(Context $context, string $name): void
    {
        if (! static::isFieldVisible($context->collection, $context, $name)) {
            throw new ResourceNotFoundException($context->collection->name(), $context->modelId);
        }
    }

    /**
     * Whether `$resource`'s field `$name` (as modified by every extension) is visible for
     * `$context`'s actor and model. A field another extension removed counts as hidden.
     */
    public static function isFieldVisible(mixed $resource, Context $context, string $name): bool
    {
        $fields = $resource instanceof AbstractResource ? $resource->resolveFields() : [];

        foreach ($fields as $field) {
            if ($field instanceof Field && $field->name === $name) {
                return $field->isVisible($context);
            }
        }

        return false;
    }
}
