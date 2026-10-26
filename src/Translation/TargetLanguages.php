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

use Flarum\Locale\LocaleManager;
use Flarum\User\User;
use FoF\Translate\Driver\DriverManager;

/**
 * Which languages an actor may translate into: forum locales the current driver
 * supports. On page loads this is a cache read (see DriverManager): until a translate
 * request has resolved the provider's list, every forum locale is offered. The
 * translate endpoint passes $resolve so the locale is validated against the real list.
 * Stateless on purpose (it is shared across requests, FND-124).
 */
class TargetLanguages
{
    public function __construct(
        protected DriverManager $drivers,
        protected LocaleManager $locales,
    ) {
    }

    /**
     * Forum locale codes (as keyed by the LocaleManager) the driver can target.
     *
     * @return list<string>
     */
    public function all(bool $resolve = false): array
    {
        $targets = $this->drivers->targetLanguages($resolve);

        if ($targets === []) {
            return [];
        }

        $locales = array_map('strval', array_keys($this->locales->getLocales()));

        // Unknown yet (or listing failed): offer the forum locales unvalidated.
        if ($targets === null) {
            return $locales;
        }

        $supported = array_flip($targets);

        return array_values(array_filter(
            $locales,
            fn (string $locale) => isset($supported[strtolower($locale)]) || isset($supported[static::primary($locale)])
        ));
    }

    /**
     * @return list<string>
     */
    public function forActor(User $actor, ?string $currentLocale, bool $resolve = false): array
    {
        if (! $actor->can('fof-translate.translate')) {
            return [];
        }

        $all = $this->all($resolve);

        if ($actor->can('fof-translate.translateAnyLanguage')) {
            return $all;
        }

        $currentLocale ??= $this->locales->getLocale();

        return in_array($currentLocale, $all, true) ? [$currentLocale] : [];
    }

    /**
     * Lower-case ISO 639-1 primary subtag of a locale code ("pt-BR" => "pt").
     */
    public static function primary(string $locale): string
    {
        return strtolower((string) preg_split('/[-_]/', $locale)[0]);
    }
}
