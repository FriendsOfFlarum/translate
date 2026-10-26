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

/**
 * A translation backend. Implementations are resolved from the container only
 * when a translation is actually requested, so constructors may be cheap but
 * must never perform network I/O.
 */
interface TranslationDriver
{
    /**
     * Stable machine name, used as the `fof-translate.driver` setting value and
     * as the `{name}` segment of the driver's settings keys
     * (`fof-translate.{name}.*`) and env var (`FOF_TRANSLATE_{NAME}_API_KEY`).
     */
    public static function name(): string;

    /**
     * Whether the driver has everything it needs (API key, base URL, model...)
     * to attempt a translation. Must not perform network I/O.
     */
    public function isConfigured(): bool;

    /**
     * @throws TranslationFailedException on any provider, transport, refusal or parsing failure
     */
    public function translate(TranslationRequest $request): TranslationResult;

    /**
     * Lower-case ISO 639-1 codes this driver can translate into. May hit the
     * network; callers cache the result (see DriverManager::targetLanguages()).
     *
     * @return list<string>
     */
    public function targetLanguages(): array;

    /**
     * Admin field definitions for the driver's settings, rendered generically by
     * the admin frontend. Must be static (no network I/O): it is computed on every
     * admin page load.
     *
     * Each entry is keyed by the setting suffix (`fof-translate.{name}.{key}`):
     *   ['type' => 'string'|'password'|'url'|'number'|'select'|'switch',
     *    'required' => bool,
     *    'options' => list<string>|array<string,string> (select only),
     *    'envOverride' => bool (true when the value comes from an env var; the admin hides the field)]
     *
     * @return array<string, array{type: string, required?: bool, options?: array, envOverride?: bool}>
     */
    public function settingsFields(): array;
}
