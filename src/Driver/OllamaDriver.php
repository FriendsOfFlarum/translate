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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Native Ollama chat API (POST {base_url}/api/chat), not the OpenAI-compatible shim,
 * so `format` can carry a full JSON schema.
 */
class OllamaDriver extends AbstractAiDriver
{
    public const DEFAULT_BASE_URL = 'http://localhost:11434';
    public const DEFAULT_TIMEOUT = 120;
    public const THINK_LEVELS = ['off', 'low', 'medium', 'high'];
    public const DEFAULT_THINK = 'low';

    protected ?ClientInterface $http = null;

    public static function name(): string
    {
        return 'ollama';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->stringSetting('model') !== null;
    }

    public function settingsFields(): array
    {
        return [
            'base_url' => ['type' => 'url', 'required' => true],
            'model' => ['type' => 'string', 'required' => true],
            'api_key' => $this->apiKeyField(required: false),
            'timeout' => ['type' => 'number', 'required' => false],
            'think' => ['type' => 'select', 'required' => false, 'options' => self::THINK_LEVELS],
        ];
    }

    protected function complete(string $system, string $user, array $schema): string
    {
        $baseUrl = $this->baseUrl();
        $model = $this->stringSetting('model');

        if ($baseUrl === null || $model === null) {
            throw new TranslationFailedException('Ollama is not configured: base URL and model are required.');
        }

        $headers = ['Accept' => 'application/json'];

        if ($apiKey = $this->apiKey()) {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        try {
            $response = $this->http()->request('POST', $baseUrl.'/api/chat', [
                'headers' => $headers,
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'stream' => false,
                    'format' => $schema,
                    'think' => $this->think(),
                    'options' => ['temperature' => 0],
                ],
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();

            throw new TranslationFailedException('Ollama request failed'.($status ? " (HTTP $status)" : '').': '.$this->errorBody($e), 0, $e);
        } catch (GuzzleException $e) {
            throw new TranslationFailedException('Ollama request failed: '.$e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! isset($body['message']['content']) || ! is_string($body['message']['content'])) {
            throw new TranslationFailedException('Ollama returned an unexpected response body.');
        }

        if (($body['done_reason'] ?? null) === 'length') {
            throw new TranslationFailedException('Ollama stopped before finishing the translation (length).');
        }

        return $body['message']['content'];
    }

    protected function label(): string
    {
        return 'Ollama';
    }

    /**
     * Never boolean true: non-thinking models answer it with HTTP 400 ("does not support thinking"),
     * while they silently accept false and a level string. Some thinking models (gpt-oss) ignore
     * false and reason anyway, so 'low' is the cheaper default.
     */
    protected function think(): string|false
    {
        $level = $this->stringSetting('think');
        $level = in_array($level, self::THINK_LEVELS, true) ? $level : self::DEFAULT_THINK;

        return $level === 'off' ? false : $level;
    }

    protected function baseUrl(): ?string
    {
        $url = $this->stringSetting('base_url') ?? self::DEFAULT_BASE_URL;

        return filter_var($url, FILTER_VALIDATE_URL) ? rtrim($url, '/') : null;
    }

    protected function http(): ClientInterface
    {
        return $this->http ??= $this->httpClient($this->numberSetting('timeout', self::DEFAULT_TIMEOUT, 5, 1800));
    }

    protected function httpClient(float $timeout): ClientInterface
    {
        return new HttpClient(['timeout' => $timeout, 'connect_timeout' => 10]);
    }

    // Ollama reports errors as {"error": "..."}; prefer that over Guzzle's message, which embeds the URL.
    protected function errorBody(RequestException $e): string
    {
        $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;

        if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
            return mb_substr($body['error'], 0, 500);
        }

        return $e->getMessage();
    }
}
