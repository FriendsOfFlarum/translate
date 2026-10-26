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
use FoF\Translate\Driver\ClaudeDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;

class ClaudeDriverTest extends DriverTestCase
{
    /**
     * @param array<string, mixed> $settings
     * @param list<Response>       $responses
     */
    protected function driver(array $settings = [], array $responses = []): ClaudeDriver
    {
        $settings += ['fof-translate.claude.api_key' => 'sk-ant-test'];
        $repo = $this->settings($settings);
        $http = $this->mockHttp($responses);

        return new class($repo, $this->locales(['en', 'de', 'zh-Hans', 'pt_BR']), new AiPromptBuilder($repo), new NullLogger(), $http) extends ClaudeDriver {
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

    /**
     * @param array<string, mixed> $overrides
     */
    protected function message(string $text, string $stopReason = 'end_turn', array $overrides = []): Response
    {
        return $this->json($overrides + [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text, 'citations' => null]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'stop_details' => null,
            'container' => null,
            'context_management' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    #[Test]
    public function builds_request_with_structured_output_effort_and_fallbacks(): void
    {
        $driver = $this->driver(responses: [$this->message('{"translation":"Hallo __12345_C_0__","source_language":"en"}')]);

        $result = $driver->translate(new TranslationRequest('Hello __12345_C_0__', 'de', context: 'Discussion title'));

        $this->assertSame('Hallo __12345_C_0__', $result->text);
        $this->assertSame('en', $result->detectedSourceLanguage);

        $request = $this->lastRequest();
        $body = $this->lastRequestBody();

        $this->assertSame('/v1/messages', $request->getUri()->getPath());
        $this->assertStringContainsString('sk-ant-test', $request->getHeaderLine('x-api-key').$request->getHeaderLine('authorization'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('betas', $body);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(['translation', 'source_language'], $body['output_config']['format']['schema']['required']);
        $this->assertStringContainsString('German', $body['system']);
        $this->assertStringContainsString('<context>', $body['messages'][0]['content']);
        $this->assertStringContainsString("<text>\nHello __12345_C_0__\n</text>", $body['messages'][0]['content']);
    }

    #[Test]
    public function haiku_is_sent_without_fallbacks(): void
    {
        $driver = $this->driver(
            ['fof-translate.claude.model' => 'claude-haiku-5-5', 'fof-translate.claude.effort' => 'medium'],
            [$this->message('{"translation":"Hallo","source_language":null}')]
        );

        $result = $driver->translate(new TranslationRequest('Hello', 'de'));

        $body = $this->lastRequestBody();
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertArrayNotHasKey('fallbacks', $body);
        $this->assertStringNotContainsString('server-side-fallback', $this->lastRequest()->getHeaderLine('anthropic-beta'));
        $this->assertNull($result->detectedSourceLanguage);
    }

    #[Test]
    public function unknown_model_and_effort_settings_fall_back_to_defaults(): void
    {
        $driver = $this->driver(
            ['fof-translate.claude.model' => 'gpt-4o', 'fof-translate.claude.effort' => 'max'],
            [$this->message('{"translation":"Hallo","source_language":"EN-us"}')]
        );

        $result = $driver->translate(new TranslationRequest('Hello', 'de'));

        $this->assertSame('claude-opus-5-5', $this->lastRequestBody()['model']);
        $this->assertSame('low', $this->lastRequestBody()['output_config']['effort']);
        $this->assertSame('en', $result->detectedSourceLanguage);
    }

    #[Test]
    public function refusal_throws(): void
    {
        $driver = $this->driver(responses: [$this->message('', 'refusal', [
            'content' => [],
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'no'],
        ])]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('refusal');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function max_tokens_stop_throws(): void
    {
        $driver = $this->driver(responses: [$this->message('{"translation":"Hal', 'max_tokens')]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('max_tokens');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function invalid_json_throws(): void
    {
        $driver = $this->driver(responses: [$this->message('Hallo')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function api_error_is_rethrown_as_translation_failure_without_the_key(): void
    {
        $driver = $this->driver(responses: [$this->json([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'bad request'],
        ], 400)]);

        try {
            $driver->translate(new TranslationRequest('Hello', 'de'));
            $this->fail('Expected TranslationFailedException');
        } catch (TranslationFailedException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringNotContainsString('sk-ant-test', $e->getMessage());
        }
    }

    #[Test]
    public function skips_thinking_blocks_before_the_text(): void
    {
        $driver = $this->driver(responses: [$this->message('', overrides: [
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => '{"translation":"Hallo","source_language":"en"}', 'citations' => null],
            ],
        ])]);

        $this->assertSame('Hallo', $driver->translate(new TranslationRequest('Hello', 'de'))->text);
    }

    #[Test]
    public function configuration_and_env_override(): void
    {
        $repo = $this->settings([]);
        $driver = new ClaudeDriver($repo, $this->locales(), new AiPromptBuilder($repo), new NullLogger());

        $this->assertFalse($driver->isConfigured());
        $this->assertArrayNotHasKey('envOverride', $driver->settingsFields()['api_key']);

        putenv('IANM_TRANSLATE_CLAUDE_API_KEY=legacy-key');

        $this->assertTrue($driver->isConfigured());
        $this->assertTrue($driver->settingsFields()['api_key']['envOverride']);
    }

    #[Test]
    public function target_languages_are_forum_locales_reduced_to_two_letters(): void
    {
        $this->assertSame(['en', 'de', 'zh', 'pt'], $this->driver()->targetLanguages());
    }

    #[Test]
    public function batch_sends_json_array_and_preserves_order(): void
    {
        $driver = $this->driver(responses: [$this->message('{"translations":["Lieblingsfarbe?","Rot","Blau __12345_G_0__"],"source_language":"en"}')]);

        $results = $driver->translateBatch([
            new TranslationRequest('Favourite colour?', 'de', 'en', context: 'Poll about colours', glossary: ['Flarum'], isPlainText: true),
            new TranslationRequest('Red', 'de', 'en', isPlainText: true),
            new TranslationRequest('Blue __12345_G_0__', 'de', 'en', isPlainText: true),
        ]);

        $this->assertSame(['Lieblingsfarbe?', 'Rot', 'Blau __12345_G_0__'], array_map(fn ($r) => $r->text, $results));
        $this->assertSame(['en', 'en', 'en'], array_map(fn ($r) => $r->detectedSourceLanguage, $results));
        $this->assertCount(1, $this->history);

        $body = $this->lastRequestBody();
        $user = $body['messages'][0]['content'];

        $this->assertStringContainsString("<context>\nPoll about colours\n</context>", $user);
        $this->assertMatchesRegularExpression('/<texts>\n(.*)\n<\/texts>/s', $user);
        preg_match('/<texts>\n(.*)\n<\/texts>/s', $user, $m);
        $this->assertSame(['Favourite colour?', 'Red', 'Blue __12345_G_0__'], json_decode($m[1], true));
        $this->assertSame(['translations', 'source_language'], $body['output_config']['format']['schema']['required']);
        $this->assertSame('array', $body['output_config']['format']['schema']['properties']['translations']['type']);
        $this->assertStringContainsString('exactly 3 translated strings', $body['system']);
        $this->assertStringContainsString('"Flarum"', $body['system']);
    }

    #[Test]
    public function batch_count_mismatch_throws(): void
    {
        $driver = $this->driver(responses: [$this->message('{"translations":["Rot"],"source_language":"en"}')]);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('1 translations for 2 strings');

        $driver->translateBatch([new TranslationRequest('Red', 'de'), new TranslationRequest('Blue', 'de')]);
    }

    #[Test]
    public function empty_batch_makes_no_call(): void
    {
        $this->assertSame([], $this->driver()->translateBatch([]));
        $this->assertEmpty($this->history);
    }
}
