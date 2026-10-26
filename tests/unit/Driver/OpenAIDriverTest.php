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
use FoF\Translate\Driver\OpenAIDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;

class OpenAIDriverTest extends DriverTestCase
{
    /**
     * @param array<string, mixed> $settings
     * @param list<Response>       $responses
     */
    protected function driver(array $settings = [], array $responses = []): OpenAIDriver
    {
        $settings += ['fof-translate.openai.api_key' => 'sk-test'];
        $repo = $this->settings($settings);
        $http = $this->mockHttp($responses);

        return new class($repo, $this->locales(), new AiPromptBuilder($repo), new NullLogger(), $http) extends OpenAIDriver {
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

    protected function completion(?string $content, string $finishReason = 'stop'): Response
    {
        return $this->json([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1700000000,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'logprobs' => null,
                'finish_reason' => $finishReason,
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
    }

    #[Test]
    public function builds_strict_json_schema_request(): void
    {
        $driver = $this->driver(
            ['fof-translate.openai.max_tokens' => '2048', 'fof-translate.ai.en_variant' => 'en-US'],
            [$this->completion('{"translation":"Hello __12345_G_0__","source_language":"de"}')]
        );

        $result = $driver->translate(new TranslationRequest('Hallo __12345_G_0__', 'en', glossary: ['Flarum']));

        $this->assertSame('Hello __12345_G_0__', $result->text);
        $this->assertSame('de', $result->detectedSourceLanguage);

        $request = $this->lastRequest();
        $body = $this->lastRequestBody();

        $this->assertStringEndsWith('/v1/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        $this->assertSame('gpt-4o', $body['model']);
        $this->assertSame(2048, $body['max_completion_tokens']);
        $this->assertSame(0.3, $body['temperature']);
        $this->assertSame('json_schema', $body['response_format']['type']);
        $this->assertTrue($body['response_format']['json_schema']['strict']);
        $this->assertFalse($body['response_format']['json_schema']['schema']['additionalProperties']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertStringContainsString('American English (en-US)', $body['messages'][0]['content']);
        $this->assertStringContainsString('"Flarum"', $body['messages'][0]['content']);
        $this->assertStringContainsString('__12345_C_0__', $body['messages'][0]['content']);
    }

    #[Test]
    public function reasoning_models_are_sent_without_temperature(): void
    {
        $driver = $this->driver(['fof-translate.openai.model' => 'gpt-5-mini'], [$this->completion('{"translation":"Hallo","source_language":"en"}')]);

        $driver->translate(new TranslationRequest('Hello', 'de'));

        $this->assertSame('gpt-5-mini', $this->lastRequestBody()['model']);
        $this->assertArrayNotHasKey('temperature', $this->lastRequestBody());
    }

    #[Test]
    public function truncated_output_throws(): void
    {
        $driver = $this->driver(responses: [$this->completion('{"translation":"Hal', 'length')]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('length');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function null_content_throws(): void
    {
        $driver = $this->driver(responses: [$this->completion(null)]);

        $this->expectException(TranslationFailedException::class);

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function api_error_is_rethrown(): void
    {
        $driver = $this->driver(responses: [$this->json([
            'error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'],
        ], 401)]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('Incorrect API key');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function not_configured_without_key(): void
    {
        $repo = $this->settings([]);
        $driver = new OpenAIDriver($repo, $this->locales(), new AiPromptBuilder($repo), new NullLogger());

        $this->assertFalse($driver->isConfigured());

        putenv('FOF_TRANSLATE_OPENAI_API_KEY=env-key');

        $this->assertTrue($driver->isConfigured());
        $this->assertTrue($driver->settingsFields()['api_key']['envOverride']);
    }

    #[Test]
    public function batch_uses_array_schema_and_preserves_order(): void
    {
        $driver = $this->driver(responses: [$this->completion('{"translations":["Ja","Nein"],"source_language":"en"}')]);

        $results = $driver->translateBatch([new TranslationRequest('Yes', 'de', isPlainText: true), new TranslationRequest('No', 'de', isPlainText: true)]);

        $this->assertSame(['Ja', 'Nein'], array_map(fn ($r) => $r->text, $results));
        $this->assertSame('en', $results[1]->detectedSourceLanguage);

        $schema = $this->lastRequestBody()['response_format']['json_schema']['schema'];
        $this->assertSame(['type' => 'string'], $schema['properties']['translations']['items']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertStringContainsString("[\n    \"Yes\",\n    \"No\"\n]", $this->lastRequestBody()['messages'][1]['content']);
    }

    #[Test]
    public function batch_count_mismatch_throws(): void
    {
        $driver = $this->driver(responses: [$this->completion('{"translations":["Ja","Nein","Vielleicht"],"source_language":"en"}')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translateBatch([new TranslationRequest('Yes', 'de'), new TranslationRequest('No', 'de')]);
    }

    #[Test]
    public function batch_without_translations_array_throws(): void
    {
        $driver = $this->driver(responses: [$this->completion('{"translation":"Ja","source_language":"en"}')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translateBatch([new TranslationRequest('Yes', 'de')]);
    }
}
