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
use FoF\Translate\Driver\TranslationRequest;
use PHPUnit\Framework\Attributes\Test;

class AiPromptBuilderTest extends DriverTestCase
{
    #[Test]
    public function prompts_require_translating_text_between_tokens(): void
    {
        $builder = new AiPromptBuilder($this->settings([]));
        $request = new TranslationRequest('Read __12345_C_0__the docs__12345_C_1__', 'de');

        foreach ([$builder->systemPrompt($request), $builder->batchSystemPrompt([$request])] as $prompt) {
            $this->assertStringContainsString('Text between or directly next to tokens is ordinary text and must still be translated', $prompt);
            $this->assertStringContainsString('__12345_C_0__the docs__12345_C_1__', $prompt);
        }
    }

    #[Test]
    public function variants_and_context(): void
    {
        $builder = new AiPromptBuilder($this->settings(['fof-translate.ai.de_variant' => 'swiss', 'fof-translate.ai.en_variant' => 'en-GB']));

        $this->assertStringContainsString('Swiss German', $builder->systemPrompt(new TranslationRequest('Hi', 'de')));
        $this->assertStringContainsString('"Velo"', $builder->systemPrompt(new TranslationRequest('Hi', 'de')));
        $this->assertStringContainsString('British English (en-GB)', $builder->systemPrompt(new TranslationRequest('Hallo', 'en', 'de')));
        $this->assertStringContainsString('from German', $builder->systemPrompt(new TranslationRequest('Hallo', 'en', 'de')));

        $withContext = new TranslationRequest('Hi', 'fr', context: str_repeat('é', 3000));
        $this->assertStringContainsString('<context> holds surrounding text', $builder->systemPrompt($withContext));
        $this->assertSame(AiPromptBuilder::CONTEXT_MAX_CHARS, mb_substr_count($builder->userMessage($withContext), 'é'));
        $this->assertStringNotContainsString('<context>', $builder->userMessage(new TranslationRequest('Hi', 'fr')));
    }

    #[Test]
    public function parse_restores_outer_whitespace_and_normalises_language(): void
    {
        $builder = new AiPromptBuilder($this->settings([]));
        $request = new TranslationRequest("Hello\n", 'de');

        $result = $builder->parseResponse("\n{\"translation\":\"\\nHallo\\n\\n\",\"source_language\":\"EN\"}\n", $request, 'Test');

        $this->assertSame("Hallo\n", $result->text);
        $this->assertSame('en', $result->detectedSourceLanguage);
        $this->assertNull($builder->normaliseLanguage('english?'));
    }
}
