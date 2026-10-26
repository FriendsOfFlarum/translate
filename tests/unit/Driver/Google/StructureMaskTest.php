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

use FoF\Translate\Driver\Google\MaskedText;
use FoF\Translate\Driver\Google\StructureMask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StructureMaskTest extends TestCase
{
    /**
     * @return list<string>
     */
    protected function tokens(MaskedText $mask, string $kind): array
    {
        return array_keys(array_filter($mask->tokens, fn (array $meta) => $meta['kind'] === $kind));
    }

    #[Test]
    public function masks_emphasis_markers_and_hard_breaks(): void
    {
        $mask = StructureMask::mask("# Title\n\nSome **bold** and _italic_ and ~~gone~~ text  \nnext line\n- item\n  1. nested");

        $this->assertStringNotContainsString('**', $mask->text);
        $this->assertStringNotContainsString('~~', $mask->text);
        $this->assertStringNotContainsString('_italic_', $mask->text);
        $this->assertStringNotContainsString('- item', $mask->text);
        $this->assertStringNotContainsString('# Title', $mask->text);
        $this->assertCount(3, $this->tokens($mask, 'open'));
        $this->assertCount(3, $this->tokens($mask, 'close'));
        $this->assertCount(3, $this->tokens($mask, 'marker'));
        $this->assertCount(1, $this->tokens($mask, 'break'));
        $this->assertStringContainsString("\n  __12345_S_", $mask->text, 'indentation stays outside the marker token');

        $this->assertNull($mask->failure($mask->text));
        $this->assertSame($mask->restoreRaw($mask->raw), $mask->restore($mask->text, 'de'));
        $this->assertSame("# Title\n\nSome **bold** and _italic_ and ~~gone~~ text  \nnext line\n- item\n  1. nested", $mask->restore($mask->text, 'de'));
    }

    #[Test]
    public function leaves_intraword_and_unpaired_delimiters_alone(): void
    {
        $source = 'snake_case and 2 * 3 * 4 and a lone * star and file_name.txt';
        $mask = StructureMask::mask($source);

        $this->assertSame([], $mask->tokens);
        $this->assertSame($source, $mask->text);
    }

    #[Test]
    public function existing_tokens_are_kept_and_never_reused(): void
    {
        $mask = StructureMask::mask('Run __12345_C_0__ and **__12345_S_4__**');

        $this->assertSame('cp', $mask->tokens['__12345_C_0__']['kind']);
        $this->assertSame('cp', $mask->tokens['__12345_S_4__']['kind']);
        $this->assertArrayHasKey('__12345_S_5__', $mask->tokens);
        $this->assertSame('open', $mask->tokens['__12345_S_5__']['kind']);
        $this->assertSame('Run __12345_C_0__ and **__12345_S_4__**', $mask->restore($mask->text, 'de'));
    }

    #[Test]
    public function glued_tokens_get_spaces_that_are_removed_again(): void
    {
        $mask = StructureMask::mask('See __12345_C_0__the docs__12345_C_1__.');

        $this->assertSame('See __12345_C_0__ the docs __12345_C_1__ .', $mask->text);
        $this->assertSame('Siehe __12345_C_0__die Doku__12345_C_1__.', $mask->restore('Siehe __12345_C_0__ die Doku __12345_C_1__ .', 'de'));
    }

    #[Test]
    public function punctuation_glue_is_kept_open_when_the_translation_moves_the_token_next_to_a_word(): void
    {
        $mask = StructureMask::mask('It is stored as __12345_C_0__.');

        $this->assertSame('punct', $mask->tokens['__12345_C_0__']['glueRight']);
        $this->assertSame('Es wird als __12345_C_0__ gespeichert.', $mask->restore('Es wird als __12345_C_0__ gespeichert.', 'de'));
        $this->assertSame('Gespeichert als __12345_C_0__.', $mask->restore('Gespeichert als __12345_C_0__ .', 'de'));
    }

    #[Test]
    public function cjk_glue_is_only_closed_up_for_cjk_targets(): void
    {
        $mask = StructureMask::mask('これは__12345_C_0__です');

        $this->assertSame('script', $mask->tokens['__12345_C_0__']['glueLeft']);
        $this->assertSame('This is __12345_C_0__ ok', $mask->restore('This is __12345_C_0__ ok', 'en'));
        $this->assertSame('这是__12345_C_0__好', $mask->restore('这是 __12345_C_0__ 好', 'zh'));
    }

    #[Test]
    public function accepts_case_and_spacing_damage_inside_tokens(): void
    {
        $mask = StructureMask::mask('Some **bold** text');

        $this->assertSame('Some __12345_S_0__ bold __12345_S_1__ text', $mask->text);

        $damaged = 'Etwas __12345_s_0__ fett __12345_s _1__ Text';

        $this->assertNull($mask->failure($damaged));
        $this->assertSame('Etwas **fett** Text', $mask->restore($damaged, 'de'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function damage(): array
    {
        $ok = "__12345_S_0__ Eins __12345_S_1__ fett __12345_S_2__\n__12345_S_3__ Zwei __12345_C_0__ __12345_S_4__\ndrei";

        return [
            'dropped token' => [str_replace('__12345_C_0__ ', '', $ok), 'missing'],
            'duplicated token' => [str_replace('Zwei', 'Zwei __12345_C_0__', $ok), 'duplicated'],
            'unexpected token' => [str_replace('drei', 'drei __12345_C_9__', $ok), 'unexpected'],
            'swapped emphasis' => [str_replace('__12345_S_1__ fett __12345_S_2__', '__12345_S_2__ fett __12345_S_1__', $ok), 'opens after'],
            'merged lines' => [str_replace("__12345_S_2__\n", '__12345_S_2__ ', $ok), 'line count'],
            'reordered lines' => ["__12345_S_3__ Zwei __12345_C_0__ __12345_S_4__\n__12345_S_0__ Eins __12345_S_1__ fett __12345_S_2__\ndrei", 'moved'],
            'changed indentation' => [str_replace("\n__12345_S_3__", "\n  __12345_S_3__", $ok), 'indentation'],
            'marker moved off line start' => [str_replace('__12345_S_0__ Eins', 'Eins __12345_S_0__', $ok), 'leading'],
            'hard break moved' => [str_replace('Zwei __12345_C_0__ __12345_S_4__', 'Zwei __12345_S_4__ __12345_C_0__', $ok), 'hard break'],
            'blank line introduced' => [str_replace('drei', '', $ok), 'blank'],
            'emphasis emptied of its words' => [str_replace('Eins __12345_S_1__ fett __12345_S_2__', 'Eins fett __12345_S_1__ , __12345_S_2__', $ok), 'lost its words'],
        ];
    }

    #[Test]
    #[DataProvider('damage')]
    public function rejects_structural_damage(string $translated, string $reason): void
    {
        $mask = StructureMask::mask("- One **bold**\n- Two __12345_C_0__  \nthree");

        $this->assertSame("__12345_S_0__ One __12345_S_1__ bold __12345_S_2__\n__12345_S_3__ Two __12345_C_0__ __12345_S_4__\nthree", $mask->text);
        $this->assertNull($mask->failure("__12345_S_0__ Eins __12345_S_1__ fett __12345_S_2__\n__12345_S_3__ Zwei __12345_C_0__ __12345_S_4__\ndrei"), 'baseline translation is accepted');
        $this->assertStringContainsString($reason, (string) $mask->failure($translated));
    }

    #[Test]
    public function translatable_text_detection(): void
    {
        $this->assertFalse(StructureMask::hasTranslatableText('__12345_C_0__ 123 !!'));
        $this->assertTrue(StructureMask::hasTranslatableText('__12345_C_0__ ok'));
        $this->assertTrue(StructureMask::hasTranslatableText('日本語'));
    }
}
