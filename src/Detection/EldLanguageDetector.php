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

use Flarum\Settings\SettingsRepositoryInterface;
use Nitotm\Eld\LanguageDetector as Eld;
use Throwable;

/**
 * nitotm/efficient-language-detector on its full language set (no subset), so a post
 * in a language the forum doesn't offer is still recognised and offered for translation.
 * Bound as a singleton: ELD loads its n-gram database lazily on first detect(), once per process.
 */
class EldLanguageDetector implements LanguageDetector
{
    protected ?Eld $eld = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function detect(string $text): ?string
    {
        $text = PlainText::from($text);

        $min = (int) $this->settings->get('fof-translate.short_content_length', 10);

        if ($text === '' || mb_strlen($text) < max(1, $min)) {
            return null;
        }

        try {
            $result = $this->eld()->detect($text);
        } catch (Throwable) {
            return null;
        }

        if (! $result->isReliable()) {
            return null;
        }

        $language = strtolower((string) $result->language);

        return preg_match('/^[a-z]{2}$/', $language) ? $language : null;
    }

    protected function eld(): Eld
    {
        return $this->eld ??= new Eld();
    }
}
