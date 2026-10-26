<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Event;

/**
 * `fof:translate:prune` deleted translations (not dispatched on a dry run).
 */
final readonly class TranslationsPruned
{
    public function __construct(
        public int $sameLanguage,
        public int $orphanedPolls,
        public int $orphanedPollOptions,
    ) {
    }
}
