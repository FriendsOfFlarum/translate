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

/**
 * Meaning of the `detected_lang` column (string(2)):
 *  - NULL:  never checked (backfill picks it up);
 *  - '':    checked, but undetermined (short or unreliable text);
 *  - 'xx':  lower-case ISO 639-1 code.
 * 'un' is treated as unknown: ianm/translate could truncate "unknown" into the column.
 */
final class Language
{
    public const UNDETERMINED = '';

    /**
     * The language a stored or reported value denotes, or null when it is not one.
     */
    public static function normalize(mixed $value): ?string
    {
        return is_string($value) && $value !== 'un' && preg_match('/^[a-z]{2}$/', $value) ? $value : null;
    }

    /**
     * Value to store after a detection attempt.
     */
    public static function toStored(?string $detected): string
    {
        return self::normalize($detected) ?? self::UNDETERMINED;
    }
}
