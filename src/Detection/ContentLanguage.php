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

use Closure;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The detection rules shared by save-time listeners and the backfill. Every method
 * returns the value to store: a language code, or Language::UNDETERMINED.
 *
 * Text too short for the detector ("Danke!", "+1") takes the author's forum locale;
 * text long enough but ambiguous stays undetermined; no text at all (an image-only
 * post) stays undetermined.
 */
class ContentLanguage
{
    public function __construct(
        protected LanguageDetector $detector,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @param Closure(): (string|null)|string|null $authorLocale the author's forum locale; a closure is only called when needed
     */
    public function ofPostXml(?string $xml, Closure|string|null $authorLocale = null): string
    {
        $text = PlainText::fromXml($xml);

        if ($text === '') {
            return Language::UNDETERMINED;
        }

        if ($this->isTooShort($text)) {
            return Language::toStored($this->resolve($authorLocale));
        }

        return Language::toStored($this->detector->detect($text));
    }

    /**
     * The title alone first; titles are short, so when that is unreliable the title
     * plus the opening post's text is tried. Only when even that is too short does the
     * author's locale apply.
     *
     * @param Closure(): (string|null)|string|null $firstPostText unparsed or plain text of the
     *                                                         first post; a closure is only called when needed
     * @param Closure(): (string|null)|string|null $authorLocale  the author's forum locale; a closure is only called when needed
     */
    public function ofDiscussion(string $title, Closure|string|null $firstPostText, Closure|string|null $authorLocale = null): string
    {
        $language = trim($title) === '' ? null : $this->detector->detect($title);

        if ($language !== null) {
            return Language::toStored($language);
        }

        $firstPostText = $this->resolve($firstPostText);
        $combined = trim($title."\n\n".(string) $firstPostText);

        if (PlainText::from($combined) === '') {
            return Language::UNDETERMINED;
        }

        if ($this->isTooShort($combined)) {
            return Language::toStored($this->resolve($authorLocale));
        }

        return Language::toStored(trim((string) $firstPostText) === '' ? null : $this->detector->detect($combined));
    }

    public function isTooShort(string $text): bool
    {
        $minimum = max(1, (int) $this->settings->get('fof-translate.short_content_length', 10));

        return mb_strlen(PlainText::from($text)) < $minimum;
    }

    protected function resolve(Closure|string|null $value): ?string
    {
        return $value instanceof Closure ? $value() : $value;
    }
}
