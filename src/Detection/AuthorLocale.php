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

use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\Translate\Translation\TargetLanguages;
use Illuminate\Database\ConnectionInterface;

/**
 * The language an author uses the forum in: their saved locale preference when it
 * is an installed locale, else the forum default. Only consulted for content too
 * short for the detector to classify.
 */
class AuthorLocale
{
    public function __construct(
        protected LocaleManager $locales,
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
    ) {
    }

    public function of(?User $author): ?string
    {
        return $this->fromPreference($author?->getPreference('locale'));
    }

    /**
     * Author locales for a batch of user ids, read with one query.
     *
     * @param list<int> $userIds
     * @return array<int, string|null>
     */
    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        $locales = [];

        if ($userIds) {
            foreach ($this->db->table('users')->whereIn('id', $userIds)->pluck('preferences', 'id') as $id => $preferences) {
                $decoded = is_string($preferences) ? json_decode($preferences, true) : null;
                $locales[(int) $id] = $this->fromPreference(is_array($decoded) ? ($decoded['locale'] ?? null) : null);
            }
        }

        return $locales;
    }

    public function fromPreference(mixed $locale): ?string
    {
        if (! is_string($locale) || ! $this->locales->hasLocale($locale)) {
            $locale = (string) $this->settings->get('default_locale');
        }

        return Language::normalize(TargetLanguages::primary($locale));
    }
}
