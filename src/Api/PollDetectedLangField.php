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

use Flarum\Api\Schema;
use FoF\Polls\Poll;
use FoF\Translate\Detection\PollLanguage;

/**
 * `detectedLang` on polls: computed with ELD (no column, no query), see PollLanguage.
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
                ->get(fn (Poll $poll) => $this->language->of($poll)),
        ];
    }
}
