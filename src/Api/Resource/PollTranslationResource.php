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
use FoF\Translate\Detection\PollLanguage;
use FoF\Translate\Model\PollOptionTranslation;
use FoF\Translate\Model\PollTranslation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registered only when fof/polls is enabled.
 *
 * @extends AbstractDatabaseResource<PollTranslation>
 */
class PollTranslationResource extends AbstractDatabaseResource
{
    public function __construct(
        protected PollLanguage $pollLanguage,
    ) {
    }

    public function type(): string
    {
        return 'poll-translations';
    }

    public function model(): string
    {
        return PollTranslation::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $query->whereHas('poll', fn (Builder $poll) => $poll->whereVisibleTo($context->getActor()));
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
            Schema\Integer::make('pollId')
                ->get(fn (PollTranslation $translation) => $translation->poll_id),
            Schema\Str::make('language'),
            Schema\Str::make('question'),
            Schema\Str::make('subtitle')->nullable(),
            Schema\Str::make('imageAlt')->nullable(),
            Schema\Str::make('provider'),
            Schema\Str::make('sourceLanguage')
                ->nullable()
                ->get(fn (PollTranslation $translation) => $translation->poll ? $this->pollLanguage->of($translation->poll) : null),
            Schema\DateTime::make('updatedAt')->nullable(),
            Schema\Boolean::make('isStale')
                ->get(fn (PollTranslation $translation) => $translation->isStale()),
            Schema\Boolean::make('isEdited')
                ->get(fn (PollTranslation $translation) => $translation->isEdited()),

            // Set by TranslationService on the translate endpoint; a plain Show resolves
            // the option rows (same language, the poll's options) with one query.
            Schema\Relationship\ToMany::make('options')
                ->type('poll-option-translations')
                ->includable()
                ->get(function (PollTranslation $translation) {
                    $translation->optionTranslations ??= PollOptionTranslation::query()
                        ->where('language', $translation->language)
                        ->whereIn('option_id', fn ($query) => $query->select('id')->from('poll_options')->where('poll_id', $translation->poll_id))
                        ->get();

                    return $translation->optionTranslations->all();
                }),
        ];
    }
}
