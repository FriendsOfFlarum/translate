<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use FoF\Translate\Detection\Language;
use FoF\Translate\Model\PostTranslation;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @extends AbstractDatabaseResource<PostTranslation>
 */
class PostTranslationResource extends AbstractDatabaseResource
{
    public function __construct(
        protected LoggerInterface $logger,
    ) {
    }

    public function type(): string
    {
        return 'post-translations';
    }

    public function model(): string
    {
        return PostTranslation::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $query->whereHas('post', fn (Builder $post) => $post->whereVisibleTo($context->getActor()));
    }

    public function endpoints(): array
    {
        // No routes: rows are only ever served by the translate endpoints, which apply the
        // subject's visibility and permission checks. The type exists for serialization.
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('postId')
                ->get(fn (PostTranslation $translation) => (string) $translation->post_id),
            Schema\Str::make('language'),
            Schema\Str::make('contentHtml')
                ->get(function (PostTranslation $translation, Context $context) {
                    $post = $translation->post;

                    if (! $post || ! $translation->content) {
                        return '';
                    }

                    try {
                        return $post->getFormatter()->render($translation->content, $post, $context->request);
                    } catch (Throwable $e) {
                        $this->logger->error("[fof/translate] failed to render post translation {$translation->id}: {$e->getMessage()}");

                        return '';
                    }
                }),
            Schema\Str::make('provider'),
            Schema\Str::make('sourceLanguage')
                ->nullable()
                ->get(fn (PostTranslation $translation) => Language::normalize($translation->post?->detected_lang)),
            Schema\DateTime::make('updatedAt')->nullable(),
            Schema\Boolean::make('isStale')
                ->get(fn (PostTranslation $translation) => $translation->isStale()),
            Schema\Boolean::make('isEdited')
                ->get(fn (PostTranslation $translation) => $translation->isEdited()),
        ];
    }
}
