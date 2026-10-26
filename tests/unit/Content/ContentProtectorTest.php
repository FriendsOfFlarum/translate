<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Content;

use Closure;
use FoF\Translate\Content\ContentProtector;
use FoF\Translate\Extend\TranslationProtection;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContentProtectorTest extends TestCase
{
    protected function protector(?array $rules = null): ContentProtector
    {
        return new ContentProtector($rules ?? ContentProtector::defaultRules());
    }

    /**
     * Each case: source text, a span that must be hidden from the translator, and (optionally)
     * a span that must stay visible (translatable).
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function rules(): array
    {
        return [
            'fenced code' => ["Look:\n```php\necho 'hi';\n```\ndone", "echo 'hi';", 'Look:'],
            'tilde fence' => ["~~~\nraw text\n~~~", 'raw text', null],
            'inline code' => ['Run `composer update` now', 'composer update', 'Run'],
            'code bbcode' => ['See [code]a < b[/code] here', 'a < b', 'See'],
            'upload preview' => ['Pic [upl-image-preview uuid=abc url=https://x.test/a.png] nice', 'upl-image-preview', 'nice'],
            'upload file' => ['[upl-file uuid=1 size=2kB]report.pdf[/upl-file]', 'report.pdf', null],
            'url bbcode' => ['Go [url=https://flarum.org]home page[/url] now', 'home page', 'Go'],
            'img bbcode' => ['[img]https://x.test/a.png[/img] caption', 'https://x.test/a.png', 'caption'],
            'html tags' => ['Hi <span class="x">inner</span> there', '<span', 'there'],
            'html void tag' => ['line one<br>line two', '<br>', 'line one'],
            'markdown image' => ['![a cat](https://x.test/cat.png) cute', '](https://x.test/cat.png)', 'cute'],
            'markdown link' => ['Read [the docs](https://docs.flarum.org "Docs") first', '](https://docs.flarum.org', 'the docs'],
            'bare url' => ['Visit https://example.com/path?a=1. Thanks', 'https://example.com/path?a=1', '. Thanks'],
            'email' => ['Mail me at user.name+x@example.co.uk please', 'user.name+x@example.co.uk', 'please'],
            'post mention' => ['@"Ian M"#p123 wrote this', '@"Ian M"#p123', 'wrote this'],
            'user mention' => ['Thanks @"Jane Doe"#42!', '@"Jane Doe"#42', 'Thanks'],
            'group mention' => ['Ping @"Mods"#g4 please', '@"Mods"#g4', 'please'],
            'legacy mention' => ['Hey @john_doe how are you', '@john_doe', 'how are you'],
            'emoji shortcode' => ['Great :smile: job', ':smile:', 'Great'],
            'quote tags' => ['[quote="Ian"]Translate me[/quote] reply', '[quote="Ian"]', 'Translate me'],
            'spoiler tags' => ['[spoiler]Hidden text[/spoiler]', '[spoiler]', 'Hidden text'],
            'card-info tags' => ['[card-info]Info here[/card-info]', '[card-info]', 'Info here'],
            'inline spoiler' => ['Ending: >!he dies!< sadly', '>!', 'he dies'],
            'indented code block' => ["Run:\n\n    php flarum cache:clear\n    php flarum migrate\n\nDone", 'php flarum', 'Done'],
            'tab-indented code' => ["\tcomposer update\nafter", 'composer update', null],
            'snake_case identifier' => ['Set snake_case_value now', 'snake_case_value', 'Set'],
            'screaming identifier' => ['Use FOF_TRANSLATE_API_KEY here', 'FOF_TRANSLATE_API_KEY', 'here'],
            'blockquote prefix' => ["> > nested quote\n> outer quote\nreply", '> >', 'nested quote'],
        ];
    }

    #[Test]
    #[DataProvider('rules')]
    public function rule_hides_span_and_round_trips(string $source, string $hidden, ?string $visible): void
    {
        $content = $this->protector()->protect($source);

        $this->assertStringNotContainsString($hidden, $content->text);

        if ($visible !== null) {
            $this->assertStringContainsString($visible, $content->text);
        }

        $this->assertMatchesRegularExpression('/__12345_C_\d+__/', $content->text);
        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function indented_code_is_one_token_and_keeps_its_line_breaks_outside(): void
    {
        $source = "Intro\n\n    line one\n\n    line two\n\nOutro";
        $content = $this->protector()->protect($source);

        $this->assertSame("Intro\n\n__12345_C_0__\n\nOutro", $content->text);
        $this->assertSame("    line one\n\n    line two", $content->placeholders['__12345_C_0__']);
        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function nested_and_continued_list_items_stay_translatable(): void
    {
        $source = "- First item\n    - Nested item\n\n    Continuation paragraph\n\n1. Numbered\n\n        still list content\n* star item\n    + plus item";
        $content = $this->protector()->protect($source);

        foreach (['Nested item', 'Continuation paragraph', 'still list content', 'plus item'] as $text) {
            $this->assertStringContainsString($text, $content->text);
        }

        $this->assertSame([], $content->placeholders);
    }

    #[Test]
    public function indented_lines_without_a_preceding_blank_line_are_not_code(): void
    {
        $source = "A paragraph\n    with an indented continuation";
        $content = $this->protector()->protect($source);

        $this->assertSame($source, $content->text);
    }

    #[Test]
    public function code_after_a_closed_list_is_protected(): void
    {
        $source = "- item\n\nParagraph\n\n    real_code()\n";
        $content = $this->protector()->protect($source);

        $this->assertStringNotContainsString('real_code', $content->text);
        $this->assertStringEndsWith("__12345_C_0__\n", $content->text);
        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function emphasis_underscores_are_not_identifiers(): void
    {
        $source = 'Some _italic_ and __bold__ text with my_var inside _a_b_';
        $content = $this->protector()->protect($source);

        $this->assertStringContainsString('_italic_', $content->text);
        $this->assertStringContainsString('__bold__', $content->text);
        $this->assertStringNotContainsString('my_var', $content->text);
        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function canonicalise_tokens_accepts_any_kind_letter_but_only_known_tokens(): void
    {
        $known = ['__12345_S_3__' => true];

        $this->assertSame('a __12345_S_3__ b __12345_x_1__', ContentProtector::canonicaliseTokens('a __12345_s _3__ b __12345_x_1__', $known));
    }

    #[Test]
    public function nested_quotes_keep_inner_text_translatable(): void
    {
        $source = "[quote=\"A\"][quote=\"B\"]inner text[/quote]outer text[/quote]";
        $content = $this->protector()->protect($source);

        $this->assertStringNotContainsString('[quote', $content->text);
        $this->assertStringNotContainsString('[/quote]', $content->text);
        $this->assertStringContainsString('inner text', $content->text);
        $this->assertStringContainsString('outer text', $content->text);

        $translated = str_replace(['inner text', 'outer text'], ['innerer Text', 'äußerer Text'], $content->text);

        $this->assertSame(
            "[quote=\"A\"][quote=\"B\"]innerer Text[/quote]äußerer Text[/quote]",
            $this->protector()->restore($translated, $content)
        );
    }

    #[Test]
    public function nested_tokens_restore_fully(): void
    {
        $source = '<div>Hi @"Ian"#p1 and `code`</div>';
        $content = $this->protector([
            'mentions' => ContentProtector::defaultRules()['mentions'],
            'html-tags' => ContentProtector::defaultRules()['html-tags'],
        ])->protect($source);

        $this->assertSame(1, preg_match_all('/__12345_C_\d+__/', $content->text));
        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function glossary_terms_are_protected_as_whole_words_and_case_sensitively(): void
    {
        $source = 'Flarum Pro beats Flarum, but not flarum or Flarumish.';
        $content = $this->protector()->protect($source, ['Flarum', ['term' => 'Flarum Pro'], '']);

        $this->assertSame('__12345_G_0__ beats __12345_G_1__, but not flarum or Flarumish.', $content->text);
        $this->assertSame('Flarum Pro', $content->placeholders['__12345_G_0__']);

        $translated = '__12345_G_0__ schlägt __12345_G_1__, aber nicht flarum oder Flarumish.';
        $this->assertSame('Flarum Pro schlägt Flarum, aber nicht flarum oder Flarumish.', $this->protector()->restore($translated, $content));
    }

    #[Test]
    public function glossary_never_matches_inside_existing_tokens(): void
    {
        $content = $this->protector()->protect('Use `x` with C', ['C', '12345']);

        $this->assertSame($content->placeholders['__12345_C_0__'], '`x`');
        $this->assertSame('C', $content->placeholders['__12345_G_1__']);
        $this->assertSame('Use `x` with C', $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function unicode_glossary_terms(): void
    {
        $content = $this->protector()->protect('Über Straße and Straßenbahn', ['Straße']);

        $this->assertSame('Über __12345_G_0__ and Straßenbahn', $content->text);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function damagedTokens(): array
    {
        return [
            'lower case' => ['Hallo __12345_c_0__ Welt'],
            'spaces inside' => ['Hallo __12345 _ C _ 0__ Welt'],
            'space before closing' => ['Hallo __12345_C_0 __ Welt'],
            'markdown escaped' => ['Hallo \_\_12345\_C\_0\_\_ Welt'],
            'bolded' => ['Hallo **12345_C_0** Welt'],
            'hyphens' => ['Hallo __12345-C-0__ Welt'],
            'non-breaking space' => ["Hallo __12345_C\u{00A0}0__ Welt"],
        ];
    }

    #[Test]
    #[DataProvider('damagedTokens')]
    public function restore_repairs_llm_damaged_tokens(string $translated): void
    {
        $content = $this->protector()->protect('Hello `code` world');

        $this->assertSame('Hallo `code` Welt', $this->protector()->restore($translated, $content));
    }

    #[Test]
    public function restore_leaves_unrelated_numbers_and_unknown_tokens_alone(): void
    {
        $content = $this->protector()->protect('Order `x` now');

        $this->assertSame('Ticket 12345 C 0 and `x` and __12345_C_9__', $this->protector()->restore('Ticket 12345 C 0 and __12345_C_0__ and __12345_C_9__', $content));
    }

    #[Test]
    public function index_ten_is_not_confused_with_index_one(): void
    {
        $source = implode(' ', array_map(fn (int $i) => "`c$i`", range(0, 11)));
        $content = $this->protector()->protect($source);

        $this->assertSame($source, $this->protector()->restore($content->text, $content));
    }

    #[Test]
    public function empty_and_plain_text_pass_through(): void
    {
        $this->assertSame([], $this->protector()->protect('')->placeholders);

        $content = $this->protector()->protect('Just words.');
        $this->assertSame('Just words.', $content->text);
        $this->assertSame('Just words.', $this->protector()->restore('Just words.', $content));
    }

    #[Test]
    public function invalid_rule_pattern_is_skipped(): void
    {
        $content = $this->protector(['broken' => ['pattern' => '/(unclosed', 'type' => 'full']])->protect('Hello');

        $this->assertSame('Hello', $content->text);
    }

    #[Test]
    public function processor_rules_receive_a_protect_closure(): void
    {
        $protector = $this->protector([
            'kbd' => [
                'pattern' => '/\[kbd\](.*?)\[\/kbd\]/',
                'processor' => fn (array $m, Closure $protect) => $protect('[kbd]').strtoupper($m[1]).$protect('[/kbd]'),
            ],
        ]);

        $content = $protector->protect('Press [kbd]enter[/kbd]');

        $this->assertSame('Press __12345_C_0__ENTER__12345_C_1__', $content->text);
        $this->assertSame('Press [kbd]ENTER[/kbd]', $protector->restore($content->text, $content));
    }

    #[Test]
    public function extender_adds_rules_to_the_container_binding(): void
    {
        $container = new Container();
        $container->singleton('fof-translate.protection-rules', fn () => ContentProtector::defaultRules());

        (new TranslationProtection())
            ->protectBBCodeSelectively('callout', 'callout')
            ->protectBBCodeFully('math', 'math')
            ->protectSelfClosingBBCode('embed', 'embed')
            ->addProtectionRule('ticket', '/#T-\d+/')
            ->extend($container);

        $rules = $container->make('fof-translate.protection-rules');
        $this->assertArrayHasKey('code-fenced', $rules);

        $protector = new ContentProtector($rules);
        $source = '[callout=warn]Be careful[/callout] [math]x^2[/math] [embed id=3] see #T-42';
        $content = $protector->protect($source);

        $this->assertStringContainsString('Be careful', $content->text);
        $this->assertStringNotContainsString('x^2', $content->text);
        $this->assertStringNotContainsString('[embed', $content->text);
        $this->assertStringNotContainsString('#T-42', $content->text);
        $this->assertStringNotContainsString('[callout', $content->text);
        $this->assertSame($source, $protector->restore($content->text, $content));
    }
}
