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

use Flarum\User\User;

/**
 * An admin ran the driver test from the settings page.
 */
final readonly class DriverTested
{
    public function __construct(
        public string $driver,
        public bool $ok,
        public User $actor,
    ) {
    }
}
