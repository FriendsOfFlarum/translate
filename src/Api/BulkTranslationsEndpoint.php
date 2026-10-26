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
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use FoF\Translate\Content\MentionRelations;
use FoF\Translate\Model\AbstractTranslation;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PostTranslation;
use FoF\Translate\Translation\TargetLanguages;
use FoF\Translate\Translation\TranslationError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * `POST /discussions/{id}/translations`, body {"data": {"attributes": {"locale": "en", "posts": ["1", "2"]}}}.
 *
 * Returns, in one response, every cached and current translation of the thread the reader
 * may see: the title and the requested posts. It is a read: it never calls a provider, so
 * the client translates whatever is missing through the per-item translate endpoints.
 * Mounted on the discussion resource, so the discussion passes its visibility scope first.
 */
abstract class BulkTranslationsEndpoint
{
    public const NAME = 'translations';

    public const ROUTE = 'discussions.translations';

    /** Ids per request: a thread page holds about 20 posts; more than this is not a page. */
    public const MAX_POSTS = 50;

    public static function make(): Endpoint
    {
        return Endpoint::make(self::NAME)
            ->route('POST', '/{id}/translations')
            ->action(function (Context $context) {
                $actor = $context->getActor();
                $actor->assertCan('fof-translate.translate');

                /** @var Discussion $discussion */
                $discussion = $context->model;

                $attributes = (array) Arr::get($context->body(), 'data.attributes', []);
                $locale = $attributes['locale'] ?? null;

                // Not resolving: a read never reaches the provider, not even for its language list.
                $targets = resolve(TargetLanguages::class)->forActor($actor, $context->request->getAttribute('locale'));

                if (! is_string($locale) || ! in_array($locale, $targets, true)) {
                    throw TranslationError::make(TranslationError::UNSUPPORTED_LOCALE);
                }

                $ids = array_slice(array_values(array_filter(array_map('intval', (array) ($attributes['posts'] ?? [])))), 0, self::MAX_POSTS);

                return [
                    'discussion-translations' => static::title($context, $discussion, $locale),
                    'post-translations' => static::posts($context, $discussion, $ids, $locale),
                ];
            })
            ->response(fn (Context $context, array $translations) => JsonApiResponse::many($context, $translations));
    }

    /**
     * @return list<DiscussionTranslation>
     */
    protected static function title(Context $context, Discussion $discussion, string $locale): array
    {
        if (! TranslateEndpoint::isFieldVisible($context->collection, $context, 'title')) {
            return [];
        }

        $row = static::servable(DiscussionTranslation::query())
            ->where('discussion_id', $discussion->id)
            ->where('language', $locale)
            ->first();

        return $row ? [$row->setRelation('discussion', $discussion)] : [];
    }

    /**
     * Only posts of this discussion that the reader may load, and whose content they may see.
     *
     * @param list<int> $ids
     * @return list<PostTranslation>
     */
    protected static function posts(Context $context, Discussion $discussion, array $ids, string $locale): array
    {
        if (! $ids) {
            return [];
        }

        $postResource = $context->api->getResource('posts');

        // The discussion already passed DiscussionResource's visibility scope, so post
        // visibility can skip re-checking it for every row.
        $posts = CommentPost::query()
            ->whereVisibleToInDiscussion($context->getActor(), $discussion)
            ->where('discussion_id', $discussion->id)
            ->where('type', CommentPost::$type)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (CommentPost $post) => TranslateEndpoint::isFieldVisible($postResource, $context->withModel($post), 'contentHtml'))
            ->keyBy('id');

        if ($posts->isEmpty()) {
            return [];
        }

        $posts->loadMissing(MentionRelations::paths());

        return static::servable(PostTranslation::query())
            ->whereIn('post_id', $posts->keys()->all())
            ->where('language', $locale)
            ->get()
            ->each(fn (PostTranslation $translation) => $translation->setRelation('post', $posts->get($translation->post_id)))
            ->values()
            ->all();
    }

    /**
     * Current rows only: a stale machine translation is left for the client to refresh;
     * a human-edited one is always served, as the per-item endpoint does.
     *
     * @template T of AbstractTranslation
     * @param Builder<T> $query
     * @return Builder<T>
     */
    protected static function servable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('update_needed', false)->orWhereNotNull('edited_by'));
    }
}
