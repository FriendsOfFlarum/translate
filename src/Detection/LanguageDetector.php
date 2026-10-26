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

interface LanguageDetector
{
    /**
     * Local, network-free detection of unparsed (Markdown/BBCode) or plain text.
     *
     * @return string|null lower-case ISO 639-1 code, or null when the text is too
     *                     short or the result is unreliable (null means "unknown")
     */
    public function detect(string $text): ?string;
}
