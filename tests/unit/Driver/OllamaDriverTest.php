<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Driver;

use FoF\Translate\Driver\AiPromptBuilder;
use FoF\Translate\Driver\OllamaDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

class OllamaDriverTest extends DriverTestCase
{
    /**
     * @param array<string, mixed> $settings
     * @param list<Response>       $responses
     */
    protected function driver(array $settings = [], array $responses = []): OllamaDriver
    {
        $settings += [
            'fof-translate.ollama.base_url' => 'http://ollama.internal:11434/',
            'fof-translate.ollama.model' => 'llama3.1',
        ];
        $repo = $this->settings($settings);
        $http = $this->mockHttp($responses);

        return new class($repo, $this->locales(['en', 'fr']), new AiPromptBuilder($repo), new NullLogger(), $http) extends OllamaDriver {
            public function __construct($settings, $locales, $prompts, $logger, protected Client $mockHttp)
            {
                parent::__construct($settings, $locales, $prompts, $logger);
            }

            protected function httpClient(float $timeout): ClientInterface
            {
                return $this->mockHttp;
            }
        };
    }

    protected function chat(string $content, string $doneReason = 'stop'): Response
    {
        return $this->json([
            'model' => 'llama3.1',
            'created_at' => '2026-10-25T00:00:00Z',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => true,
            'done_reason' => $doneReason,
        ]);
    }

    #[Test]
    public function posts_native_chat_request_with_schema_format(): void
    {
        $driver = $this->driver(responses: [$this->chat('{"translation":"Bonjour","source_language":"en"}')]);

        $result = $driver->translate(new TranslationRequest('Hello', 'fr', isPlainText: true));

        $this->assertSame('Bonjour', $result->text);
        $this->assertSame('en', $result->detectedSourceLanguage);

        $request = $this->lastRequest();
        $body = $this->lastRequestBody();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://ollama.internal:11434/api/chat', (string) $request->getUri());
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame('llama3.1', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame(0, $body['options']['temperature']);
        $this->assertSame('object', $body['format']['type']);
        $this->assertSame(['translation', 'source_language'], $body['format']['required']);
        $this->assertStringContainsString('French', $body['messages'][0]['content']);
        $this->assertStringContainsString('plain text', $body['messages'][0]['content']);
    }

    #[Test]
    public function sends_bearer_token_when_configured(): void
    {
        $driver = $this->driver(['fof-translate.ollama.api_key' => 'proxy-token'], [$this->chat('{"translation":"Bonjour","source_language":null}')]);

        $driver->translate(new TranslationRequest('Hello', 'fr'));

        $this->assertSame('Bearer proxy-token', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function tolerates_fenced_json(): void
    {
        $driver = $this->driver(responses: [$this->chat("```json\n{\"translation\":\"Bonjour\",\"source_language\":\"en\"}\n```")]);

        $this->assertSame('Bonjour', $driver->translate(new TranslationRequest('Hello', 'fr'))->text);
    }

    #[Test]
    public function http_error_uses_ollama_error_message(): void
    {
        $driver = $this->driver(responses: [$this->json(['error' => 'model "llama3.1" not found, try pulling it first'], 404)]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('not found, try pulling it first');

        $driver->translate(new TranslationRequest('Hello', 'fr'));
    }

    #[Test]
    public function length_stop_throws(): void
    {
        $driver = $this->driver(responses: [$this->chat('{"translation":"Bon', 'length')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translate(new TranslationRequest('Hello', 'fr'));
    }

    #[Test]
    public function configured_needs_a_model(): void
    {
        $this->assertTrue($this->driver()->isConfigured());
        $this->assertFalse($this->driver(['fof-translate.ollama.model' => ''])->isConfigured());
        $this->assertFalse($this->driver(['fof-translate.ollama.base_url' => 'not a url'])->isConfigured());
        $this->assertSame(['en', 'fr'], $this->driver()->targetLanguages());
    }

    #[Test]
    public function think_defaults_to_low_and_never_sends_true(): void
    {
        $responses = fn () => [$this->chat('{"translation":"Bonjour","source_language":"en"}')];

        $this->driver(responses: $responses())->translate(new TranslationRequest('Hello', 'fr'));
        $this->assertSame('low', $this->lastRequestBody()['think']);

        $this->driver(['fof-translate.ollama.think' => 'off'], $responses())->translate(new TranslationRequest('Hello', 'fr'));
        $this->assertFalse($this->lastRequestBody()['think']);

        $this->driver(['fof-translate.ollama.think' => 'high'], $responses())->translate(new TranslationRequest('Hello', 'fr'));
        $this->assertSame('high', $this->lastRequestBody()['think']);

        $this->driver(['fof-translate.ollama.think' => '1'], $responses())->translate(new TranslationRequest('Hello', 'fr'));
        $this->assertSame('low', $this->lastRequestBody()['think']);

        $this->assertSame(['off', 'low', 'medium', 'high'], $this->driver()->settingsFields()['think']['options']);
    }

    #[Test]
    public function batch_preserves_order(): void
    {
        $driver = $this->driver(responses: [$this->chat('{"translations":["Oui","Non"],"source_language":"en"}')]);

        $results = $driver->translateBatch([new TranslationRequest('Yes', 'fr'), new TranslationRequest('No', 'fr')]);

        $this->assertSame(['Oui', 'Non'], array_map(fn ($r) => $r->text, $results));
        $this->assertSame('array', $this->lastRequestBody()['format']['properties']['translations']['type']);
    }

    #[Test]
    public function batch_count_mismatch_throws(): void
    {
        $driver = $this->driver(responses: [$this->chat('{"translations":["Oui"],"source_language":"en"}')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translateBatch([new TranslationRequest('Yes', 'fr'), new TranslationRequest('No', 'fr')]);
    }
}
