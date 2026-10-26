<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Detection;

use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use WeakMap;

/**
 * Polls are detected on the fly, never stored (their table belongs to fof/polls). Uses
 * question + subtitle, plus the answers only when the options relation is already
 * loaded, so it never queries. Memoized per model instance: the WeakMap entry dies
 * with the model, so nothing leaks across requests (FND-124).
 */
class PollLanguage
{
    /** @var WeakMap<Poll, string> */
    protected WeakMap $memo;

    public function __construct(
        protected LanguageDetector $detector,
    ) {
        $this->memo = new WeakMap();
    }

    public function of(Poll $poll): ?string
    {
        if (! isset($this->memo[$poll])) {
            $parts = [(string) $poll->question, (string) $poll->subtitle];

            if ($poll->relationLoaded('options')) {
                foreach ($poll->options as $option) {
                    if ($option instanceof PollOption) {
                        $parts[] = (string) $option->answer;
                    }
                }
            }

            $text = trim(implode("\n", array_filter($parts, fn (string $part) => trim($part) !== '')));

            $this->memo[$poll] = $text === '' ? Language::UNDETERMINED : Language::toStored($this->detector->detect($text));
        }

        return Language::normalize($this->memo[$poll]);
    }
}
