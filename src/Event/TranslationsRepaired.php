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
 * `fof:translate:repair-xml --fix` marked corrupted translations for re-translation.
 */
final readonly class TranslationsRepaired
{
    public function __construct(
        public int $rows,
    ) {
    }
}
