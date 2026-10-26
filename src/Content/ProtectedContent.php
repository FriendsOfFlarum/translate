<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Content;

/**
 * Output of ContentProtector::protect(): the text to send to a driver, plus the
 * placeholder => original map ContentProtector::restore() needs afterwards.
 */
final readonly class ProtectedContent
{
    /**
     * @param array<string, string> $placeholders placeholder token => original span
     */
    public function __construct(
        public string $text,
        public array $placeholders = [],
    ) {
    }
}
