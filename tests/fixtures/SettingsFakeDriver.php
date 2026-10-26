<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\fixtures;

use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Translate\Driver\AiPromptBuilder;
use FoF\Translate\Driver\TranslationDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use FoF\Translate\Driver\TranslationResult;

/**
 * Settings-dependent fake for the driver test endpoint: configured only with a model,
 * fails when the model is "explode" (leaking its key, to exercise redaction).
 */
class SettingsFakeDriver implements TranslationDriver
{
    /** @var list<array{model: string|null, request: TranslationRequest, enVariant: mixed}> */
    public static array $calls = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected AiPromptBuilder $prompts,
    ) {
    }

    public static function name(): string
    {
        return 'fake-settings';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('fof-translate.fake-settings.model')) !== '';
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $model = $this->settings->get('fof-translate.fake-settings.model');

        static::$calls[] = [
            'model' => $model,
            'request' => $request,
            'enVariant' => $this->settings->get('fof-translate.ai.en_variant'),
            'prompt' => $this->prompts->systemPrompt($request),
        ];

        if ($model === 'explode') {
            throw new TranslationFailedException('Unauthorized for key '.$this->settings->get('fof-translate.fake-settings.api_key'));
        }

        return new TranslationResult('['.$request->targetLanguage.'] '.$request->text, $request->sourceLanguage);
    }

    public function targetLanguages(): array
    {
        return ['en', 'de', 'fr'];
    }

    public function settingsFields(): array
    {
        return [
            'model' => ['type' => 'string', 'required' => true],
            'api_key' => ['type' => 'password', 'required' => false],
        ];
    }
}
