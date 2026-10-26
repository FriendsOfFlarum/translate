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

// Copy-only upgrade from ianm/translate: old rows are left alone, and a key already
// set for fof-translate is never overwritten, so re-running is a no-op.
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // old suffix => new suffix
        $map = [
            'deepl.api_key' => 'deepl.api_key',
            'deepl.formality' => 'deepl.formality',
            'deepl.en_choice' => 'deepl.en_choice',
            'deepl.model_type' => 'deepl.model_type',
            'openai.api_key' => 'openai.api_key',
            'openai.translation_model' => 'openai.model',
            'openai.en_variant' => 'ai.en_variant',
            'openai.de_variant' => 'ai.de_variant',
            'openai.max_tokens' => 'openai.max_tokens',
            'openai.timeout' => 'openai.timeout',
            'glossary' => 'glossary',
            'translate-all-enabled' => 'translate-all-enabled',
            'use-native-locale-names' => 'use-native-locale-names',
        ];

        $old = $db->table('settings')->where('key', 'like', 'ianm-translate.%')->pluck('value', 'key');

        if ($old->isEmpty()) {
            return;
        }

        $copy = [];

        foreach ($old as $key => $value) {
            $suffix = substr($key, strlen('ianm-translate.'));

            if ($suffix === 'provider') {
                // 1.x provider names → 2.x driver names; anything else is left for the admin to pick.
                $driver = ['deepl' => 'deepl', 'openai' => 'openai', 'google-free' => 'google'][$value] ?? null;

                if ($driver !== null) {
                    $copy['fof-translate.driver'] = $driver;
                }
            } elseif (isset($map[$suffix])) {
                $copy['fof-translate.'.$map[$suffix]] = $value;
            } elseif (preg_match('/^deepl\.glossary_[a-z-]+_to_[a-z-]+$/i', $suffix)) {
                // Per language-pair DeepL glossary ids (glossary_{src}_to_{tgt}); glossary_default is dead.
                $copy['fof-translate.'.$suffix] = $value;
            }
        }

        if (! $copy) {
            return;
        }

        $existing = $db->table('settings')->whereIn('key', array_keys($copy))->pluck('key')->all();

        $rows = [];

        foreach (array_diff_key($copy, array_flip($existing)) as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value];
        }

        if ($rows) {
            $db->table('settings')->insert($rows);
        }
    },
    'down' => function (Builder $schema) {
        // Copy-only migration; the copied values belong to fof-translate now.
    },
];
