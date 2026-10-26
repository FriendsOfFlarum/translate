<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Adopts the identical column ianm/translate created; only what is missing is added.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('discussions', 'detected_lang')) {
            $schema->table('discussions', function (Blueprint $table) {
                $table->string('detected_lang', 2)->nullable();
            });
        }

        if (! $schema->hasIndex('discussions', ['detected_lang'])) {
            $schema->table('discussions', function (Blueprint $table) {
                $table->index('detected_lang');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('discussions', 'detected_lang')) {
            // Drop by the index's real name: an adopted ianm/translate index need not carry
            // Laravel's conventional name (e.g. `flarum_discussions_detected_lang_index`).
            foreach ($schema->getIndexes('discussions') as $index) {
                if ($index['columns'] === ['detected_lang'] && ! $index['primary']) {
                    $schema->table('discussions', function (Blueprint $table) use ($index) {
                        $table->dropIndex($index['name']);
                    });
                }
            }

            $schema->table('discussions', function (Blueprint $table) {
                $table->dropColumn('detected_lang');
            });
        }
    },
];
