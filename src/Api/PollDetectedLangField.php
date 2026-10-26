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
use Flarum\Api\Schema;
use FoF\Polls\Poll;
use FoF\Translate\Detection\PollLanguage;

/**
 * `detectedLang` on polls: computed with ELD (no column, no query), see PollLanguage.
 * Detection costs CPU on every poll listed, so it only runs for readers who can translate:
 * nobody else is offered the button that reads it.
 */
class PollDetectedLangField
{
    public function __construct(
        protected PollLanguage $language,
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Str::make('detectedLang')
                ->nullable()
                ->visible(fn (Poll $poll, Context $context) => $context->getActor()->can('fof-translate.translate'))
                ->get(fn (Poll $poll) => $this->language->of($poll)),
        ];
    }
}
