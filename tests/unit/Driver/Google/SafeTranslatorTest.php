<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Driver\Google;

use Closure;
use FoF\Translate\Content\ContentProtector;
use FoF\Translate\Driver\Google\SafeTranslator;
use FoF\Translate\Driver\TranslationFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fake "translation" upper-cases the text. Tokens are already upper-case, so a faithful
 * translation of any source is exactly mb_strtoupper(source) — any other output is mangled.
 */
class SafeTranslatorTest extends TestCase
{
    /** @var list<string> */
    protected array $sent = [];

    protected const POST = "## Setup\n\nHi there, here is **how** to _clear_ the cache:  \nRun this:\n\n    php flarum cache:clear\n\n- First item with `inline` code\n  - Nested **bold** item\n- Second item\n\n> quoted *text* here";

    protected function protect(string $text): string
    {
        return (new ContentProtector(ContentProtector::defaultRules()))->protect($text)->text;
    }

    /**
     * @param Closure(string, int): string $damage receives the text and the 0-based call number
     */
    protected function translator(Closure $damage, int $maxRequests = SafeTranslator::MAX_REQUESTS, int $maxChunk = SafeTranslator::MAX_CHUNK): SafeTranslator
    {
        $this->sent = [];

        return new SafeTranslator(function (string $text) use ($damage) {
            $call = count($this->sent);
            $this->sent[] = $text;

            return [$damage(mb_strtoupper($text), $call), 'en'];
        }, 'de', $maxRequests, $maxChunk);
    }

    #[Test]
    public function faithful_response_passes_first_time_in_one_request(): void
    {
        $source = $this->protect(self::POST);
        $translator = $this->translator(fn (string $t) => $t);

        [$result, $detected] = $translator->translate($source);

        $this->assertSame(mb_strtoupper($source), $result);
        $this->assertSame('en', $detected);
        $this->assertCount(1, $this->sent);
        $this->assertStringNotContainsString('**', $this->sent[0]);
        $this->assertSame(1, $translator->stats()['verified']);
    }

    /**
     * @return array<string, array{Closure(string, int): string}>
     */
    public static function firstResponseDamage(): array
    {
        return [
            'drops a token' => [fn (string $t, int $call) => $call === 0 ? preg_replace('/__12345_S_1__ /', '', $t, 1) : $t],
            'swaps emphasis open/close' => [fn (string $t, int $call) => $call === 0 ? strtr($t, ['__12345_S_1__' => '__12345_S_2__', '__12345_S_2__' => '__12345_S_1__']) : $t],
            'merges lines' => [fn (string $t, int $call) => $call === 0 ? preg_replace('/\n(?!\n)/', ' ', $t, 1) : $t],
            'reorders lines' => [fn (string $t, int $call) => $call === 0 ? implode("\n", array_reverse(explode("\n", $t))) : $t],
            'strips the hard break' => [fn (string $t, int $call) => $call === 0 ? str_replace(' __12345_S_3__', '', $t) : $t],
        ];
    }

    #[Test]
    #[DataProvider('firstResponseDamage')]
    public function damaged_response_falls_back_to_paragraphs_and_stays_intact(Closure $damage): void
    {
        $source = $this->protect(self::POST);
        $translator = $this->translator($damage);

        [$result] = $translator->translate($source);

        $this->assertSame(mb_strtoupper($source), $result);
        $this->assertSame(1, $translator->stats()['paragraph_fallbacks']);
        $this->assertGreaterThan(1, count($this->sent));
    }

    #[Test]
    public function persistent_token_damage_ends_in_segment_mode_without_sending_tokens(): void
    {
        $source = $this->protect(self::POST);
        // Every response that carries a token loses its first one.
        $translator = $this->translator(fn (string $t) => preg_replace('/__12345_[A-Z]_\d+__/', '', $t, 1));

        [$result] = $translator->translate($source);

        $this->assertSame(mb_strtoupper($source), $result);
        $this->assertGreaterThan(0, $translator->stats()['segment_fallbacks']);

        $last = $this->sent[count($this->sent) - 1];
        $this->assertDoesNotMatchRegularExpression('/__12345_/', $last);
    }

    #[Test]
    public function segment_runs_fall_back_to_one_request_each_when_lines_merge(): void
    {
        $source = 'Some **bold** words and _more_ here';
        // Token responses are damaged; the joined runs come back merged into one line.
        $translator = $this->translator(fn (string $t) => str_contains($t, '__12345_') ? str_replace('__12345_S_0__', '', $t) : str_replace("\n", ' ', $t));

        [$result] = $translator->translate($source);

        $this->assertSame(mb_strtoupper($source), $result);
        $this->assertSame(1, $translator->stats()['run_fallbacks']);
    }

    #[Test]
    public function request_cap_fails_closed(): void
    {
        $translator = $this->translator(fn (string $t) => preg_replace('/__12345_[A-Z]_\d+__/', '', $t, 1) ?? $t, maxRequests: 3);

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('more than 3 requests');

        $translator->translate($this->protect(self::POST));
    }

    #[Test]
    public function long_text_is_chunked_at_paragraph_boundaries(): void
    {
        $paragraph = str_repeat('word ', 30)."**bold** end.";
        $source = implode("\n\n", array_fill(0, 6, $paragraph));
        $translator = $this->translator(fn (string $t) => $t, maxChunk: 400);

        [$result] = $translator->translate($source);

        $this->assertSame(mb_strtoupper($source), $result);
        $this->assertGreaterThan(1, count($this->sent));

        foreach ($this->sent as $sent) {
            // Glue spaces can add a few characters on top of the 400-character chunk.
            $this->assertLessThanOrEqual(420, mb_strlen($sent));
            $this->assertStringStartsWith('word', $sent);
            $this->assertStringEndsWith('end.', $sent);
        }
    }

    #[Test]
    public function untranslatable_text_sends_nothing_and_crlf_survives(): void
    {
        $translator = $this->translator(fn (string $t) => $t);

        $this->assertSame(['__12345_C_0__ 42', null], $translator->translate('__12345_C_0__ 42'));
        $this->assertSame([], $this->sent);

        [$result] = $translator->translate("Line **one**\r\nline two\r\n");
        $this->assertSame("LINE **ONE**\r\nLINE TWO\r\n", $result);
    }

    #[Test]
    public function attempt_returns_null_instead_of_falling_back(): void
    {
        $translator = $this->translator(fn (string $t) => str_replace("\n", ' ', $t));

        $this->assertNull($translator->attempt("Yes\nNo"));
        $this->assertCount(1, $this->sent);

        $translator = $this->translator(fn (string $t) => $t);
        $this->assertSame("YES\nNO", $translator->attempt("Yes\nNo"));
    }
}
