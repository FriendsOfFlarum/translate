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

use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use FoF\Translate\Detection\Language;
use FoF\Translate\Model\DiscussionTranslation;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends AbstractDatabaseResource<DiscussionTranslation>
 */
class DiscussionTranslationResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'discussion-translations';
    }

    public function model(): string
    {
        return DiscussionTranslation::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $query->whereHas('discussion', fn (Builder $discussion) => $discussion->whereVisibleTo($context->getActor()));
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
            Schema\Str::make('language'),
            Schema\Str::make('title')
                ->get(fn (DiscussionTranslation $translation) => $translation->translation),
            Schema\Str::make('provider'),
            Schema\Str::make('sourceLanguage')
                ->nullable()
                ->get(fn (DiscussionTranslation $translation) => Language::normalize($translation->discussion?->detected_lang)),
            Schema\DateTime::make('updatedAt')->nullable(),
            Schema\Boolean::make('isStale')
                ->get(fn (DiscussionTranslation $translation) => $translation->isStale()),
            Schema\Boolean::make('isEdited')
                ->get(fn (DiscussionTranslation $translation) => $translation->isEdited()),
        ];
    }
}
