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

use RuntimeException;

/**
 * Thrown by drivers for any failure. The message is logged, never shown to users;
 * TranslationService maps it to a generic translated API error and caches nothing.
 */
class TranslationFailedException extends RuntimeException
{
}
