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

final readonly class TranslationRequest
{
    /**
     * @param string        $text           Unparsed source (Markdown/BBCode) with protected spans already replaced by
     *                                      ContentProtector placeholders. Drivers must return every placeholder verbatim.
     * @param string        $targetLanguage Lower-case ISO 639-1 code.
     * @param string|null   $sourceLanguage Lower-case ISO 639-1 code, or null to let the provider detect it.
     * @param string|null   $context        Surrounding text (discussion title, first-post excerpt) to improve quality.
     *                                      Never translated or returned.
     * @param list<string>  $glossary       Terms that must stay untranslated (already placeholder-protected in $text;
     *                                      passed so AI drivers can mention them in the prompt).
     * @param bool          $isPlainText    True for titles/poll strings: no markup to preserve.
     */
    public function __construct(
        public string $text,
        public string $targetLanguage,
        public ?string $sourceLanguage = null,
        public ?string $context = null,
        public array $glossary = [],
        public bool $isPlainText = false,
    ) {
    }
}
