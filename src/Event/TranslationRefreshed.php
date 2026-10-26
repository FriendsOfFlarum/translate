<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Event;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * A reader with the refresh permission forced a re-translation (a paid call that bypasses the cache).
 */
final readonly class TranslationRefreshed
{
    public function __construct(
        public AbstractModel $subject,
        public string $locale,
        public User $actor,
    ) {
    }
}
