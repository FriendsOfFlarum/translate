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

// ianm/translate added MySQL FULLTEXT indexes for multi-language search, which this
// extension does not ship. Nothing reads them, and they tax every translation write.
return [
    'up' => function (Builder $schema) {
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['post_translations' => 'content', 'discussion_translations' => 'translation'] as $tableName => $column) {
            if (! $schema->hasTable($tableName)) {
                continue;
            }

            foreach ($schema->getIndexes($tableName) as $index) {
                if (strtolower((string) $index['type']) === 'fulltext' && $index['columns'] === [$column]) {
                    $schema->table($tableName, function (Blueprint $table) use ($index) {
                        $table->dropFullText($index['name']);
                    });
                }
            }
        }
    },
    'down' => function (Builder $schema) {
        // Intentionally not restored.
    },
];
