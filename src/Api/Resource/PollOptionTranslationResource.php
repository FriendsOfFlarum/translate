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
use FoF\Translate\Model\PollOptionTranslation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Only ever serialized as an include of poll-translations; no endpoints of its own.
 *
 * @extends AbstractDatabaseResource<PollOptionTranslation>
 */
class PollOptionTranslationResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'poll-option-translations';
    }

    public function model(): string
    {
        return PollOptionTranslation::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $query->whereHas('option.poll', fn (Builder $poll) => $poll->whereVisibleTo($context->getActor()));
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('optionId')
                ->get(fn (PollOptionTranslation $translation) => $translation->option_id),
            Schema\Str::make('language'),
            Schema\Str::make('answer'),
            Schema\Str::make('provider'),
            Schema\DateTime::make('updatedAt')->nullable(),
            Schema\Boolean::make('isStale')
                ->get(fn (PollOptionTranslation $translation) => $translation->isStale()),
            Schema\Boolean::make('isEdited')
                ->get(fn (PollOptionTranslation $translation) => $translation->isEdited()),
        ];
    }
}
