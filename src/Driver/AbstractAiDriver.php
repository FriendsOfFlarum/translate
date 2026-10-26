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

use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Translate\Driver\Concerns\ResolvesApiKey;
use Psr\Log\LoggerInterface;

/**
 * Request/response handling shared by the LLM drivers; each driver only implements the
 * transport in complete().
 */
abstract class AbstractAiDriver implements BatchTranslationDriver
{
    use ResolvesApiKey;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LocaleManager $locales,
        protected AiPromptBuilder $prompts,
        protected LoggerInterface $logger,
    ) {
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $raw = $this->complete(
            $this->prompts->systemPrompt($request),
            $this->prompts->userMessage($request),
            $this->prompts->responseSchema(),
        );

        return $this->prompts->parseResponse($raw, $request, $this->label());
    }

    public function translateBatch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $requests = array_values($requests);

        $raw = $this->complete(
            $this->prompts->batchSystemPrompt($requests),
            $this->prompts->batchUserMessage($requests),
            $this->prompts->batchResponseSchema(),
        );

        return $this->prompts->parseBatchResponse($raw, $requests, $this->label());
    }

    /**
     * Sends one system + user exchange constrained to $schema and returns the model's raw JSON text.
     *
     * @param array<string, mixed> $schema
     *
     * @throws TranslationFailedException on transport, refusal or truncation
     */
    abstract protected function complete(string $system, string $user, array $schema): string;

    /**
     * Provider name used in exception messages.
     */
    abstract protected function label(): string;

    /**
     * Any LLM can write any language the forum has a language pack for.
     *
     * @return list<string>
     */
    public function targetLanguages(): array
    {
        $codes = [];

        foreach (array_keys($this->locales->getLocales()) as $locale) {
            $code = strtolower(preg_split('/[-_]/', (string) $locale)[0]);

            if (preg_match('/^[a-z]{2}$/', $code)) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    protected function numberSetting(string $key, float $default, float $min, float $max): float
    {
        $value = $this->settings->get('fof-translate.'.static::name().'.'.$key);

        if (! is_numeric($value) || (float) $value <= 0) {
            return $default;
        }

        return max($min, min($max, (float) $value));
    }
}
