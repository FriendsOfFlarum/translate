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

use FoF\Translate\Driver\DeepLDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;

class DeepLDriverTest extends DriverTestCase
{
    /**
     * @param array<string, mixed> $settings
     * @param list<Response>       $responses
     */
    protected function driver(array $settings = [], array $responses = []): DeepLDriver
    {
        $settings += ['fof-translate.deepl.api_key' => 'deepl-key:fx'];
        $http = $this->mockHttp($responses);

        return new class($this->settings($settings), new NullLogger(), $http) extends DeepLDriver {
            public function __construct($settings, $logger, protected Client $mockHttp)
            {
                parent::__construct($settings, $logger);
            }

            protected function httpClient(): ?ClientInterface
            {
                return $this->mockHttp;
            }
        };
    }

    protected function translation(string $text, string $detected = 'DE'): Response
    {
        return $this->json(['translations' => [[
            'text' => $text,
            'detected_source_language' => $detected,
            'billed_characters' => strlen($text),
        ]]]);
    }

    #[Test]
    public function sends_plain_text_with_options_and_returns_detected_source(): void
    {
        $driver = $this->driver([
            'fof-translate.deepl.formality' => 'prefer_less',
            'fof-translate.deepl.model_type' => 'prefer_quality_optimized',
            'fof-translate.deepl.en_choice' => 'en-US',
            'fof-translate.deepl.glossary_de_to_en' => 'gloss-1',
        ], [$this->translation('Hello __12345_C_0__ & <b>')]);

        $result = $driver->translate(new TranslationRequest('Hallo __12345_C_0__ & <b>', 'en', 'de', context: 'Titel'));

        $this->assertSame('Hello __12345_C_0__ & <b>', $result->text);
        $this->assertSame('de', $result->detectedSourceLanguage);

        $request = $this->lastRequest();
        $body = $this->lastRequestBody();

        $this->assertSame('api-free.deepl.com', $request->getUri()->getHost());
        $this->assertSame('/v2/translate', $request->getUri()->getPath());
        $this->assertSame(['Hallo __12345_C_0__ & <b>'], $body['text']);
        $this->assertSame('en-US', $body['target_lang']);
        $this->assertSame('de', $body['source_lang']);
        $this->assertSame('prefer_less', $body['formality']);
        $this->assertSame('prefer_quality_optimized', $body['model_type']);
        $this->assertSame('gloss-1', $body['glossary_id']);
        $this->assertSame('Titel', $body['context']);
        $this->assertArrayNotHasKey('tag_handling', $body);
    }

    /**
     * DeepL rejects bare EN and PT targets. Unsaved, the variant is the one the settings
     * page shows as selected (Extend\Settings defaults in extend.php).
     */
    #[Test]
    public function maps_bare_en_and_pt_targets_and_skips_glossary_without_source(): void
    {
        $driver = $this->driver(['fof-translate.deepl.glossary_de_to_pt' => 'gloss-2'], [
            $this->translation('Hello', 'DE'),
            $this->translation('Olá', 'DE'),
        ]);

        $driver->translate(new TranslationRequest('Hallo', 'en'));
        $this->assertSame('en-US', $this->lastRequestBody()['target_lang']);
        $this->assertArrayNotHasKey('source_lang', $this->lastRequestBody());

        $driver->translate(new TranslationRequest('Hallo', 'pt'));
        $this->assertSame('pt-BR', $this->lastRequestBody()['target_lang']);
        $this->assertArrayNotHasKey('glossary_id', $this->lastRequestBody());
    }

    #[Test]
    public function glossary_none_is_ignored(): void
    {
        $driver = $this->driver(['fof-translate.deepl.glossary_de_to_fr' => 'none'], [$this->translation('Bonjour')]);

        $driver->translate(new TranslationRequest('Hallo', 'fr', 'de'));

        $this->assertArrayNotHasKey('glossary_id', $this->lastRequestBody());
    }

    #[Test]
    public function retries_without_a_rejected_glossary(): void
    {
        $driver = $this->driver(['fof-translate.deepl.glossary_de_to_fr' => 'gone'], [
            $this->json(['message' => 'Invalid glossary_id'], 400),
            $this->translation('Bonjour'),
        ]);

        $this->assertSame('Bonjour', $driver->translate(new TranslationRequest('Hallo', 'fr', 'de'))->text);
        $this->assertArrayNotHasKey('glossary_id', $this->lastRequestBody());
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function api_errors_become_translation_failures(): void
    {
        $driver = $this->driver(responses: [$this->json(['message' => 'Wrong endpoint'], 403)]);

        $this->expectException(TranslationFailedException::class);

        $driver->translate(new TranslationRequest('Hallo', 'fr'));
    }

    #[Test]
    public function target_languages_are_two_letter_and_unique(): void
    {
        $driver = $this->driver(responses: [$this->json([
            ['language' => 'DE', 'name' => 'German', 'supports_formality' => true],
            ['language' => 'EN-GB', 'name' => 'English (British)', 'supports_formality' => false],
            ['language' => 'EN-US', 'name' => 'English (American)', 'supports_formality' => false],
            ['language' => 'ZH-HANS', 'name' => 'Chinese (simplified)', 'supports_formality' => false],
        ])]);

        $this->assertSame(['de', 'en', 'zh'], $driver->targetLanguages());
    }

    #[Test]
    public function lists_glossaries_by_language_pair(): void
    {
        $driver = $this->driver(responses: [$this->json(['glossaries' => [[
            'glossary_id' => 'g1',
            'name' => 'Forum terms',
            'creation_time' => '2026-01-01T00:00:00.000Z',
            'dictionaries' => [
                ['source_lang' => 'de', 'target_lang' => 'en', 'entry_count' => 3],
                ['source_lang' => 'en', 'target_lang' => 'fr', 'entry_count' => 2],
            ],
        ]]])]);

        $this->assertSame([
            'de_to_en' => ['g1' => 'Forum terms (DE → EN)'],
            'en_to_fr' => ['g1' => 'Forum terms (EN → FR)'],
        ], $driver->listGlossaries());
        $this->assertSame('/v3/glossaries', $this->lastRequest()->getUri()->getPath());
    }

    #[Test]
    public function settings_fields_are_static(): void
    {
        $fields = $this->driver()->settingsFields();

        $this->assertSame(['api_key', 'formality', 'en_choice', 'pt_choice', 'model_type'], array_keys($fields));
        $this->assertEmpty($this->history);
    }

    #[Test]
    public function batch_sends_all_texts_in_one_call_and_preserves_order(): void
    {
        $driver = $this->driver(responses: [$this->json(['translations' => [
            ['text' => 'Lieblingsfarbe?', 'detected_source_language' => 'EN', 'billed_characters' => 17],
            ['text' => 'Rot', 'detected_source_language' => 'EN', 'billed_characters' => 3],
        ]])]);

        $results = $driver->translateBatch([
            new TranslationRequest('Favourite colour?', 'de', context: 'Poll', isPlainText: true),
            new TranslationRequest('Red', 'de', isPlainText: true),
        ]);

        $this->assertSame(['Lieblingsfarbe?', 'Rot'], array_map(fn ($r) => $r->text, $results));
        $this->assertSame('en', $results[1]->detectedSourceLanguage);
        $this->assertCount(1, $this->history);
        $this->assertSame(['Favourite colour?', 'Red'], $this->lastRequestBody()['text']);
        $this->assertSame('Poll', $this->lastRequestBody()['context']);
    }

    #[Test]
    public function batch_count_mismatch_throws(): void
    {
        $driver = $this->driver(responses: [$this->translation('Rot')]);

        $this->expectException(TranslationFailedException::class);

        $driver->translateBatch([new TranslationRequest('Red', 'de'), new TranslationRequest('Blue', 'de')]);
    }
}
