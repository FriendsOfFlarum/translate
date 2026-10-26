<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver;

final readonly class TranslationResult
{
    /**
     * @param string      $text                   Translated text, placeholders intact.
     * @param string|null $detectedSourceLanguage Lower-case ISO 639-1 code the provider saw, when it reports one.
     *                                            Feeds learn-on-translate (overwrites the stored detected_lang).
     */
    public function __construct(
        public string $text,
        public ?string $detectedSourceLanguage = null,
    ) {
    }
}
