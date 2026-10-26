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

// Copy-only: grants of ianm/translate's abilities are duplicated under the new keys
// for every group that holds them; the old rows are left untouched. editTranslations
// is dropped (manual editing is not ported). Re-running is a no-op.
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $map = [
            'translateLocale' => 'fof-translate.translate',
            'translateAll' => 'fof-translate.translateAnyLanguage',
            'refreshTranslation' => 'fof-translate.refresh',
        ];

        $grants = $db->table('group_permission')
            ->whereIn('permission', array_merge(array_keys($map), array_values($map)))
            ->get(['group_id', 'permission']);

        $present = [];

        foreach ($grants as $grant) {
            $present[$grant->permission][(int) $grant->group_id] = true;
        }

        $rows = [];

        foreach ($map as $old => $new) {
            foreach (array_keys($present[$old] ?? []) as $groupId) {
                if (! isset($present[$new][$groupId])) {
                    $rows[] = ['group_id' => $groupId, 'permission' => $new];
                }
            }
        }

        if ($rows) {
            $db->table('group_permission')->insert($rows);
        }
    },
    'down' => function (Builder $schema) {
        // Grants are removed with the defaults migration's down step.
    },
];
