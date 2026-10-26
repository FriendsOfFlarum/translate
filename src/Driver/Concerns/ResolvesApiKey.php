<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver\Concerns;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Resolution order: env FOF_TRANSLATE_{NAME}_API_KEY, legacy env IANM_TRANSLATE_{NAME}_API_KEY
 * (ianm/translate 1.x), then the `fof-translate.{name}.api_key` setting.
 *
 * @property SettingsRepositoryInterface $settings
 */
trait ResolvesApiKey
{
    protected function apiKey(): ?string
    {
        return $this->envApiKey() ?? $this->stringSetting('api_key');
    }

    protected function envApiKey(): ?string
    {
        $name = strtoupper(static::name());

        foreach (["FOF_TRANSLATE_{$name}_API_KEY", "IANM_TRANSLATE_{$name}_API_KEY"] as $variable) {
            // vlucas/phpdotenv (behind Illuminate\Support\Env) is not a Flarum dependency.
            $value = $_ENV[$variable] ?? $_SERVER[$variable] ?? getenv($variable);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{type: string, required: bool, envOverride?: bool}
     */
    protected function apiKeyField(bool $required = true): array
    {
        $field = ['type' => 'password', 'required' => $required];

        if ($this->envApiKey() !== null) {
            $field['envOverride'] = true;
        }

        return $field;
    }

    protected function stringSetting(string $key): ?string
    {
        $value = $this->settings->get('fof-translate.'.static::name().'.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
