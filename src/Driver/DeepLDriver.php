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

use DeepL\DeepLClient;
use DeepL\DeepLException;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use DeepL\TranslatorOptions;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Translate\Driver\Concerns\ResolvesApiKey;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

class DeepLDriver implements BatchTranslationDriver
{
    use ResolvesApiKey;

    public const FORMALITIES = ['default', 'prefer_more', 'prefer_less'];
    public const EN_CHOICES = ['en-US', 'en-GB'];
    public const PT_CHOICES = ['pt-PT', 'pt-BR'];
    public const MODEL_TYPES = ['latency_optimized', 'prefer_quality_optimized'];

    public const CONTEXT_MAX_CHARS = 4000;

    protected ?DeepLClient $client = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $logger,
    ) {
    }

    public static function name(): string
    {
        return 'deepl';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Glossary selects (`glossary_{src}_to_{tgt}`) are not listed here: they need a network
     * call, so the admin loads them lazily through listGlossaries().
     */
    public function settingsFields(): array
    {
        return [
            'api_key' => $this->apiKeyField(),
            'formality' => ['type' => 'select', 'required' => false, 'options' => self::FORMALITIES],
            'en_choice' => ['type' => 'select', 'required' => false, 'options' => self::EN_CHOICES],
            'pt_choice' => ['type' => 'select', 'required' => false, 'options' => self::PT_CHOICES],
            'model_type' => ['type' => 'select', 'required' => false, 'options' => self::MODEL_TYPES],
        ];
    }

    /**
     * $request->text is unparsed Markdown/BBCode with markup already placeholder-protected, so it
     * is sent as plain text: no tag_handling, which would entity-escape bare < > & (the #103 corruption).
     */
    public function translate(TranslationRequest $request): TranslationResult
    {
        return $this->translateBatch([$request])[0];
    }

    /**
     * One /v2/translate call with every text; options (context, glossary, formality) come from
     * the first request since all share target and source language.
     */
    public function translateBatch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $requests = array_values($requests);
        $first = $requests[0];
        $target = strtolower($first->targetLanguage);
        $source = $first->sourceLanguage ? strtolower($first->sourceLanguage) : null;
        $options = $this->options($first, $source, $target);
        $texts = array_map(fn (TranslationRequest $r) => $r->text, $requests);

        try {
            $results = $this->send($texts, $source, $this->deeplTarget($target), $options);
        } catch (DeepLException $e) {
            throw new TranslationFailedException('DeepL request failed: '.$e->getMessage(), 0, $e);
        }

        if (count($results) !== count($requests)) {
            throw new TranslationFailedException(sprintf('DeepL returned %d translations for %d texts.', count($results), count($requests)));
        }

        return array_map(
            fn (TextResult $result) => new TranslationResult($result->text, $this->normaliseLanguage($result->detectedSourceLang)),
            $results
        );
    }

    /**
     * @return list<string>
     */
    public function targetLanguages(): array
    {
        $codes = [];

        foreach ($this->client()->getTargetLanguages() as $language) {
            $code = $this->normaliseLanguage($language->code);

            if ($code !== null) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Not filtered by forum locale: detection covers every language, so a zh → en glossary is
     * useful on an English-only forum.
     *
     * @return array<string, array<string, string>> ['{src}_to_{tgt}' => [glossaryId => 'name (SRC → TGT)']]
     *
     * @throws DeepLException
     */
    public function listGlossaries(): array
    {
        $pairs = [];

        foreach ($this->client()->listMultilingualGlossaries() as $glossary) {
            foreach ($glossary->dictionaries as $dictionary) {
                $source = $this->normaliseLanguage($dictionary->sourceLang);
                $target = $this->normaliseLanguage($dictionary->targetLang);

                if ($source === null || $target === null) {
                    continue;
                }

                $pairs["{$source}_to_{$target}"][$glossary->glossaryId] = sprintf(
                    '%s (%s → %s)',
                    $glossary->name,
                    strtoupper($source),
                    strtoupper($target)
                );
            }
        }

        ksort($pairs);

        return $pairs;
    }

    /**
     * @param list<string>         $texts
     * @param array<string, mixed> $options
     * @return list<TextResult>
     *
     * @throws DeepLException
     */
    protected function send(array $texts, ?string $source, string $target, array $options): array
    {
        try {
            return $this->translateText($texts, $source, $target, $options);
        } catch (DeepLException $e) {
            // Port of 1.x: a deleted glossary or a model type unavailable for the pair should degrade, not fail.
            $retry = $options;

            if (isset($retry[TranslateTextOptions::GLOSSARY]) && stripos($e->getMessage(), 'glossary') !== false) {
                unset($retry[TranslateTextOptions::GLOSSARY]);
            } elseif (isset($retry[TranslateTextOptions::MODEL_TYPE]) && stripos($e->getMessage(), 'model_type') !== false) {
                unset($retry[TranslateTextOptions::MODEL_TYPE]);
            } else {
                throw $e;
            }

            $this->logger->warning('[fof/translate] DeepL rejected an option, retrying without it: '.$e->getMessage());

            return $this->translateText($texts, $source, $target, $retry);
        }
    }

    /**
     * @param list<string>         $texts
     * @param array<string, mixed> $options
     * @return list<TextResult>
     *
     * @throws DeepLException
     */
    protected function translateText(array $texts, ?string $source, string $target, array $options): array
    {
        $result = $this->client()->translateText($texts, $source, $target, $options);

        return array_values(is_array($result) ? $result : [$result]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(TranslationRequest $request, ?string $source, string $target): array
    {
        $options = [
            TranslateTextOptions::PRESERVE_FORMATTING => true,
        ];

        if ($request->isPlainText) {
            $options[TranslateTextOptions::SPLIT_SENTENCES] = 'off';
        }

        $formality = $this->stringSetting('formality');

        if (in_array($formality, self::FORMALITIES, true)) {
            $options[TranslateTextOptions::FORMALITY] = $formality;
        }

        $modelType = $this->stringSetting('model_type');

        if (in_array($modelType, self::MODEL_TYPES, true)) {
            $options[TranslateTextOptions::MODEL_TYPE] = $modelType;
        }

        if ($request->context !== null && trim($request->context) !== '') {
            $options[TranslateTextOptions::CONTEXT] = mb_substr(trim($request->context), 0, self::CONTEXT_MAX_CHARS);
        }

        // Glossaries need a known source; without one we skip it rather than spend a detect call.
        if ($source !== null && ($glossary = $this->glossaryFor($source, $target))) {
            $options[TranslateTextOptions::GLOSSARY] = $glossary;
        }

        return $options;
    }

    protected function glossaryFor(string $source, string $target): ?string
    {
        $id = $this->stringSetting("glossary_{$source}_to_{$target}");

        return $id !== null && $id !== 'none' ? $id : null;
    }

    /**
     * DeepL rejects bare "en" and "pt" as target languages. The fallbacks match the
     * defaults in extend.php, so an unsaved setting means what the settings page shows.
     */
    protected function deeplTarget(string $target): string
    {
        return match ($target) {
            'en' => in_array($choice = $this->stringSetting('en_choice'), self::EN_CHOICES, true) ? $choice : 'en-US',
            'pt' => in_array($choice = $this->stringSetting('pt_choice'), self::PT_CHOICES, true) ? $choice : 'pt-BR',
            default => $target,
        };
    }

    protected function normaliseLanguage(?string $code): ?string
    {
        $code = strtolower(substr(trim((string) $code), 0, 2));

        return preg_match('/^[a-z]{2}$/', $code) ? $code : null;
    }

    protected function client(): DeepLClient
    {
        if ($this->client === null) {
            $apiKey = $this->apiKey();

            if ($apiKey === null) {
                throw new TranslationFailedException('DeepL is not configured: no API key.');
            }

            $options = [
                TranslatorOptions::MAX_RETRIES => 2,
                TranslatorOptions::TIMEOUT => 20.0,
                TranslatorOptions::SEND_PLATFORM_INFO => false,
            ];

            if ($http = $this->httpClient()) {
                $options[TranslatorOptions::HTTP_CLIENT] = $http;
            }

            // The SDK picks api-free.deepl.com itself for ":fx" keys.
            $this->client = new DeepLClient($apiKey, $options);
        }

        return $this->client;
    }

    /**
     * Null uses the SDK's built-in cURL transport. Overridden in tests.
     */
    protected function httpClient(): ?ClientInterface
    {
        return null;
    }
}
