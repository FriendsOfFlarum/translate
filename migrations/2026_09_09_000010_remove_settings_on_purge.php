<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

// Purge only: core's uninstall runs down steps but never deletes an extension's settings,
// which would leave stored API keys behind. Disabling runs no down steps, so it keeps them.
// Covers third-party drivers' `fof-translate.{driver}.*` keys; legacy `ianm-translate.*`
// settings belong to the old extension and are left alone.
return [
    'up' => function (Builder $schema) {
    },
    'down' => function (Builder $schema) {
        // The prefix holds no LIKE wildcards (`%`, `_`), so it matches literally.
        $schema->getConnection()->table('settings')->where('key', 'like', 'fof-translate.%')->delete();
    },
];
