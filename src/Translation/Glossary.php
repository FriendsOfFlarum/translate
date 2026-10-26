<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Translation;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Terms that are never translated: `fof-translate.glossary` (JSON list) plus "Flarum".
 */
class Glossary
{
    public const DEFAULT_TERMS = ['Flarum'];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @return list<string>
     */
    public function terms(): array
    {
        $configured = json_decode((string) $this->settings->get('fof-translate.glossary', '[]'), true);

        $terms = self::DEFAULT_TERMS;

        if (is_array($configured)) {
            foreach ($configured as $term) {
                // The 1.x admin stored either plain strings or {term: ...} objects.
                $term = is_array($term) ? ($term['term'] ?? null) : $term;

                if (is_string($term) && trim($term) !== '') {
                    $terms[] = trim($term);
                }
            }
        }

        return array_values(array_unique($terms));
    }
}
