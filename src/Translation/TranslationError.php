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

use Flarum\Foundation\ValidationException;

/**
 * 422 with a translated, generic message (provider errors are logged, never shown).
 *
 * The attribute key tells the frontend what kind of failure it is:
 *  - `locale` (source.pointer /data/attributes/locale): this language is not available
 *    for this content (already in it, or not offered); retrying will not help;
 *  - `translation` (/data/attributes/translation): the translation could not be made
 *    right now or for this content (no driver, failure, too long, not translatable).
 */
class TranslationError extends ValidationException
{
    public const NO_DRIVER = 'fof-translate.api.no_driver';
    public const FAILED = 'fof-translate.api.translation_failed';
    public const ALREADY_IN_LANGUAGE = 'fof-translate.api.already_in_language';
    public const UNSUPPORTED_LOCALE = 'fof-translate.api.unsupported_locale';
    public const NOT_TRANSLATABLE = 'fof-translate.api.not_translatable';
    public const TOO_LONG = 'fof-translate.api.too_long';

    protected const LOCALE_ERRORS = [self::ALREADY_IN_LANGUAGE, self::UNSUPPORTED_LOCALE];

    public static function make(string $key): static
    {
        $attribute = in_array($key, self::LOCALE_ERRORS, true) ? 'locale' : 'translation';

        return new static([$attribute => resolve('translator')->trans($key)]);
    }
}
