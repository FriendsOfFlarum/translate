<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */
use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

// Fresh installs only. On a forum upgraded from ianm/translate the copied grants are
// the admin's choice, including having revoked one entirely, so nothing is added there.
// A key that already has any grant is left exactly as it is.
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $upgraded = $db->table('settings')->where('key', 'like', 'ianm-translate.%')->exists()
            || $db->table('group_permission')->whereIn('permission', ['translateLocale', 'translateAll', 'refreshTranslation'])->exists()
            || ($schema->hasTable('post_translations') && $db->table('post_translations')->exists())
            || ($schema->hasTable('discussion_translations') && $db->table('discussion_translations')->exists());

        if ($upgraded) {
            return;
        }

        $defaults = [
            'fof-translate.translate' => Group::GUEST_ID,
            'fof-translate.translateAnyLanguage' => Group::MODERATOR_ID,
        ];

        $granted = $db->table('group_permission')
            ->whereIn('permission', array_keys($defaults))
            ->distinct()
            ->pluck('permission')
            ->all();

        foreach (array_diff_key($defaults, array_flip($granted)) as $permission => $groupId) {
            if ($db->table('groups')->where('id', $groupId)->exists()) {
                $db->table('group_permission')->insert(['group_id' => $groupId, 'permission' => $permission]);
            }
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('group_permission')
            ->whereIn('permission', ['fof-translate.translate', 'fof-translate.translateAnyLanguage', 'fof-translate.refresh'])
            ->delete();
    },
];
