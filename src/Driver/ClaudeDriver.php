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

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIStatusException;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Client\ClientInterface;
use Throwable;

class ClaudeDriver extends AbstractAiDriver
{
    public const MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const EFFORTS = ['low', 'medium', 'high'];
    public const DEFAULT_EFFORT = 'low';
    public const DEFAULT_TIMEOUT = 60;

    // Server-side refusal fallback; Haiku 5.5 has none.
    public const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    // Non-streaming ceiling: room for a long post plus adaptive thinking, still under HTTP timeouts.
    public const MAX_TOKENS = 16000;

    protected ?Client $client = null;

    public static function name(): string
    {
        return 'claude';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function settingsFields(): array
    {
        return [
            'api_key' => $this->apiKeyField(),
            'model' => ['type' => 'select', 'required' => false, 'options' => self::MODELS],
            'effort' => ['type' => 'select', 'required' => false, 'options' => self::EFFORTS],
            'timeout' => ['type' => 'number', 'required' => false],
        ];
    }

    protected function complete(string $system, string $user, array $schema): string
    {
        $model = $this->model();

        // `thinking` is deliberately omitted: Opus 5.5 rejects `disabled`, and omitting it runs adaptive thinking, steered by effort.
        $params = [
            'maxTokens' => self::MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $user]],
            'model' => $model,
            'system' => $system,
            'outputConfig' => [
                'effort' => $this->effort(),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];

        if (in_array($model, self::FALLBACK_MODELS, true)) {
            $params['fallbacks'] = 'default';
            $params['betas'] = [self::FALLBACK_BETA];
        }

        try {
            $message = $this->client()->beta->messages->create(...$params);
        } catch (APIStatusException $e) {
            throw new TranslationFailedException("Claude API returned HTTP {$e->status}: ".$e->getMessage(), 0, $e);
        } catch (AnthropicException $e) {
            throw new TranslationFailedException('Claude request failed: '.$e->getMessage(), 0, $e);
        } catch (TranslationFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TranslationFailedException('Claude request failed: '.$e::class.': '.$e->getMessage(), 0, $e);
        }

        return $this->responseText($message);
    }

    protected function label(): string
    {
        return 'Claude';
    }

    protected function responseText(BetaMessage $message): string
    {
        $stopReason = $message->stopReason;

        if ($stopReason === 'refusal') {
            $category = $message->stopDetails->category ?? 'unspecified';

            throw new TranslationFailedException("Claude declined to translate (refusal, category: $category).");
        }

        if (in_array($stopReason, ['max_tokens', 'model_context_window_exceeded'], true)) {
            throw new TranslationFailedException("Claude stopped before finishing the translation ($stopReason).");
        }

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                return $block->text;
            }
        }

        throw new TranslationFailedException('Claude returned no text content (stop reason: '.($stopReason ?? 'none').').');
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $apiKey = $this->apiKey();

            if ($apiKey === null) {
                throw new TranslationFailedException('Claude is not configured: no API key.');
            }

            $timeout = $this->numberSetting('timeout', self::DEFAULT_TIMEOUT, 5, 600);

            $this->client = new Client(
                apiKey: $apiKey,
                requestOptions: [
                    'timeout' => $timeout,
                    // Translation runs inside the reader's request; one retry bounds the wait at ~2x timeout.
                    'maxRetries' => 1,
                    'transporter' => $this->httpClient($timeout),
                ],
            );
        }

        return $this->client;
    }

    /**
     * The SDK does not enforce timeouts itself; the PSR-18 transport does.
     */
    protected function httpClient(float $timeout): ClientInterface
    {
        return new HttpClient(['timeout' => $timeout, 'connect_timeout' => 10]);
    }

    protected function model(): string
    {
        $model = $this->stringSetting('model');

        return in_array($model, self::MODELS, true) ? $model : self::DEFAULT_MODEL;
    }

    protected function effort(): string
    {
        $effort = $this->stringSetting('effort');

        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }
}
