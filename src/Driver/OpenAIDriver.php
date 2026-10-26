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

use GuzzleHttp\Client as HttpClient;
use OpenAI;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use Psr\Http\Client\ClientInterface;
use Throwable;

class OpenAIDriver extends AbstractAiDriver
{
    public const DEFAULT_MODEL = 'gpt-4o';
    public const DEFAULT_MAX_TOKENS = 4096;
    public const DEFAULT_TIMEOUT = 30;

    protected ?Client $client = null;

    public static function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function settingsFields(): array
    {
        return [
            'api_key' => $this->apiKeyField(),
            'model' => ['type' => 'string', 'required' => false],
            'max_tokens' => ['type' => 'number', 'required' => false],
            'timeout' => ['type' => 'number', 'required' => false],
        ];
    }

    protected function complete(string $system, string $user, array $schema): string
    {
        try {
            $response = $this->client()->chat()->create($this->payload($system, $user, $schema));
        } catch (ErrorException $e) {
            throw new TranslationFailedException('OpenAI API error: '.$e->getMessage(), 0, $e);
        } catch (TranslationFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TranslationFailedException('OpenAI request failed: '.$e::class.': '.$e->getMessage(), 0, $e);
        }

        $choice = $response->choices[0] ?? null;

        if ($choice === null) {
            throw new TranslationFailedException('OpenAI returned no choices.');
        }

        if (in_array($choice->finishReason, ['length', 'content_filter'], true)) {
            throw new TranslationFailedException("OpenAI stopped before finishing the translation ({$choice->finishReason}).");
        }

        if ($choice->message->content === null) {
            throw new TranslationFailedException('OpenAI returned no content (the model may have refused).');
        }

        return $choice->message->content;
    }

    protected function label(): string
    {
        return 'OpenAI';
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function payload(string $system, string $user, array $schema): array
    {
        $model = $this->stringSetting('model') ?? self::DEFAULT_MODEL;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_completion_tokens' => (int) $this->numberSetting('max_tokens', self::DEFAULT_MAX_TOKENS, 256, 128000),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'translation',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        // Reasoning models (o-series, gpt-5) reject any temperature but the default.
        if (preg_match('/^(gpt-4|gpt-3\.5|chatgpt-4o)/i', $model)) {
            $payload['temperature'] = 0.3;
        }

        return $payload;
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $apiKey = $this->apiKey();

            if ($apiKey === null) {
                throw new TranslationFailedException('OpenAI is not configured: no API key.');
            }

            $this->client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient($this->httpClient($this->numberSetting('timeout', self::DEFAULT_TIMEOUT, 5, 600)))
                ->make();
        }

        return $this->client;
    }

    protected function httpClient(float $timeout): ClientInterface
    {
        return new HttpClient(['timeout' => $timeout, 'connect_timeout' => 10]);
    }
}
