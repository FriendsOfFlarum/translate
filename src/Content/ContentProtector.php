<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Content;

use Closure;

/**
 * Replaces spans that must survive translation untouched (code, links, mentions,
 * markup, glossary terms) with opaque tokens, and puts them back afterwards.
 *
 * Token format: `__12345_C_{n}__` for protected markup/code, `__12345_G_{n}__` for
 * glossary terms, where {n} is a 0-based counter unique within one protect() call.
 * Digits and underscores pass through DeepL, LLMs and Google-style tokenizers intact.
 *
 * A rule is ['pattern' => regex, 'type' => 'full'|'selective', 'processor' => ?callable]:
 *  - full: the whole match becomes one token;
 *  - selective: groups 1 and 3 become tokens, group 2 stays translatable;
 *  - processor: fn (array $matches, Closure $protect): string, where $protect(string): string
 *    returns the token for a span — for rules that need custom splitting.
 */
class ContentProtector
{
    public const TOKEN_PREFIX = '__12345_';
    public const TOKEN_SUFFIX = '__';
    public const KIND_PROTECTED = 'C';
    public const KIND_GLOSSARY = 'G';

    protected const TOKEN_PATTERN = '__12345_[CG]_\d+__';

    // Restoring a full-rule token can reveal tokens it swallowed (e.g. a mention inside an HTML block).
    protected const MAX_NESTING = 8;

    /**
     * @param array<string, array{pattern: string, type?: string, processor?: callable|null}> $rules
     */
    public function __construct(
        protected array $rules = [],
    ) {
    }

    /**
     * Rules run in order; earlier rules win, so code is protected before anything that could match inside it.
     *
     * @return array<string, array{pattern: string, type: string}>
     */
    public static function defaultRules(): array
    {
        return [
            'code-fenced' => ['pattern' => '/(```|~~~).*?\1/s', 'type' => 'full'],
            // Matches the whole text once; the processor walks it line by line.
            'code-indented' => ['pattern' => '/\A[\s\S]+\z/', 'type' => 'full', 'processor' => [self::class, 'protectIndentedCode']],
            'code-inline' => ['pattern' => '/`[^`]+`/', 'type' => 'full'],
            'code-tags' => ['pattern' => '/\[code(?:[=\s][^\]]*)?\].*?\[\/code\]/is', 'type' => 'full'],
            // fof/upload: [upl-image-preview ...] is self-closing, [upl-file ...]...[/upl-file] is not.
            'upload-tags' => ['pattern' => '/\[(upl-[a-z-]+)(?:[=\s][^\]]*)?\](?:[^\[]*\[\/\1\])?/i', 'type' => 'full'],
            'url-tags' => ['pattern' => '/\[url(?:[=\s][^\]]*)?\].*?\[\/url\]/is', 'type' => 'full'],
            'img-tags' => ['pattern' => '/\[img(?:[=\s][^\]]*)?\].*?\[\/img\]/is', 'type' => 'full'],
            'html-tags' => ['pattern' => '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>.*?<\/\1>/s', 'type' => 'full'],
            'html-void-tags' => ['pattern' => '/<(?:br|hr|img|wbr)\b[^>]*>/i', 'type' => 'full'],
            'markdown-image' => ['pattern' => '/!\[[^\]\n]*\]\([^)\n]*\)/', 'type' => 'full'],
            // Link text stays translatable; the target URL (and optional title) is protected.
            'markdown-link' => ['pattern' => '/(\[)([^\]\n]+)(\]\([^)\s]+(?:\s+"[^"\n]*")?\))/', 'type' => 'selective'],
            'bare-url' => ['pattern' => '/\b(?:https?|ftp):\/\/[^\s<>\[\]"\'`]*[^\s<>\[\]"\'`.,;:!?)]/i', 'type' => 'full'],
            'email' => ['pattern' => '/(?<![\w.+-])[\w.+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', 'type' => 'full'],
            // Flarum mentions (@"Name"#123, @"Name"#p123, @"Group"#g4, #"Tag"#t5): DeepL would otherwise swap the quotes («Name»).
            'mentions' => ['pattern' => '/[@#]"[^"\n]*"#[a-z]?\d+/i', 'type' => 'full'],
            'mentions-legacy' => ['pattern' => '/(?<![\w@])@[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_])?/', 'type' => 'full'],
            // snake_case / SCREAMING_CASE identifiers; Google capitalises or splits them. The
            // lookarounds keep _emphasis_ and existing tokens (__12345_C_0__) out.
            'identifier' => ['pattern' => '/(?<![\p{L}\p{N}_])[A-Za-z0-9]+(?:_[A-Za-z0-9]+)+(?![\p{L}\p{N}_])/u', 'type' => 'full'],
            'emoji-shortcode' => ['pattern' => '/(?<![\w:]):[a-z0-9_+-]*[a-z][a-z0-9_+-]*:(?![\w:])/i', 'type' => 'full'],
            'quote' => ['pattern' => self::bbcodeTagPattern('quote'), 'type' => 'full'],
            'spoiler' => ['pattern' => self::bbcodeTagPattern('spoiler'), 'type' => 'full'],
            'card-info' => ['pattern' => self::bbcodeTagPattern('card-info'), 'type' => 'full'],
            // Must precede markdown-blockquote, whose leading ">" would otherwise eat the ">!" opener.
            'inline-spoiler' => ['pattern' => '/(>!)(.*?)(!<)/s', 'type' => 'selective'],
            // Nested quotes unparse as ">> " or "> > "; protect the whole prefix so it is never escaped or dropped.
            'markdown-blockquote' => ['pattern' => '/^((?:> ?)+)(?!!)/m', 'type' => 'full'],
        ];
    }

    /**
     * Matches the opening (with attributes) and closing tag of a BBCode separately, so the
     * content stays translatable and nested tags of the same name are handled.
     */
    public static function bbcodeTagPattern(string $tagName): string
    {
        $tag = preg_quote($tagName, '/');

        return "/\[\/?{$tag}(?:[=\s][^\]]*)?\]/i";
    }

    /**
     * @param list<string|array{term: string}> $glossary terms that must stay untranslated (case-sensitive, whole words)
     */
    public function protect(string $text, array $glossary = []): ProtectedContent
    {
        if (trim($text) === '') {
            return new ProtectedContent($text);
        }

        $placeholders = [];

        $protect = function (string $original, string $kind = self::KIND_PROTECTED) use (&$placeholders): string {
            if ($original === '') {
                return '';
            }

            $token = self::TOKEN_PREFIX.$kind.'_'.count($placeholders).self::TOKEN_SUFFIX;
            $placeholders[$token] = $original;

            return $token;
        };

        foreach ($this->rules as $rule) {
            $text = $this->applyRule($text, $rule, $protect);
        }

        $text = $this->protectGlossary($text, $glossary, $protect);

        return new ProtectedContent($text, $placeholders);
    }

    public function restore(string $translated, ProtectedContent $content): string
    {
        if ($content->placeholders === []) {
            return $translated;
        }

        $translated = $this->repairTokens($translated, $content->placeholders);

        for ($depth = 0; $depth < self::MAX_NESTING; $depth++) {
            $restored = strtr($translated, $content->placeholders);

            if ($restored === $translated) {
                break;
            }

            $translated = $restored;
        }

        return $translated;
    }

    /**
     * @param array{pattern: string, type?: string, processor?: callable|null} $rule
     */
    protected function applyRule(string $text, array $rule, Closure $protect): string
    {
        $callback = function (array $matches) use ($rule, $protect): string {
            if (! empty($rule['processor'])) {
                return (string) call_user_func($rule['processor'], $matches, $protect);
            }

            if (($rule['type'] ?? 'full') === 'selective' && isset($matches[3])) {
                return $protect($matches[1]).$matches[2].$protect($matches[3]);
            }

            return $protect($matches[0]);
        };

        // A malformed third-party pattern (or invalid UTF-8 under /u) must not take translation down with it.
        $result = @preg_replace_callback($rule['pattern'], $callback, $text);

        return is_string($result) ? $result : $text;
    }

    /**
     * @param list<string|array{term: string}> $glossary
     */
    protected function protectGlossary(string $text, array $glossary, Closure $protect): string
    {
        $terms = [];

        foreach ($glossary as $item) {
            $term = is_array($item) ? ($item['term'] ?? '') : $item;

            if (is_string($term) && trim($term) !== '') {
                $terms[] = trim($term);
            }
        }

        if ($terms === []) {
            return $text;
        }

        $terms = array_unique($terms);
        // Longest first so "Flarum Pro" wins over "Flarum".
        usort($terms, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $alternation = implode('|', array_map(fn (string $term) => preg_quote($term, '/'), $terms));

        // Existing tokens are matched first and passed through so a term can never match inside one.
        $pattern = '/('.self::TOKEN_PATTERN.')|(?<![\p{L}\p{N}_])(?:'.$alternation.')(?![\p{L}\p{N}_])/u';

        $result = @preg_replace_callback(
            $pattern,
            fn (array $m) => isset($m[1]) && $m[1] !== '' ? $m[1] : $protect($m[0], self::KIND_GLOSSARY),
            $text
        );

        return is_string($result) ? $result : $text;
    }

    /**
     * @param array<string, string> $placeholders
     */
    protected function repairTokens(string $text, array $placeholders): string
    {
        return self::canonicaliseTokens($text, $placeholders);
    }

    /**
     * Normalises tokens damaged by a provider back to their canonical form: changed case,
     * spaces or hyphens inside, Markdown-escaped (\_\_) or bolded (**...**) underscores.
     * Only tokens that are keys of $known are rewritten; any kind letter is accepted so other
     * token families in the same format (see Driver\Google\StructureMask) can reuse it.
     *
     * @param array<string, mixed> $known canonical token => anything
     */
    public static function canonicaliseTokens(string $text, array $known): string
    {
        if ($known === [] || stripos($text, '12345') === false) {
            return $text;
        }

        $sep = '(?:\\\\?_|[ \t\x{00A0}-])';

        $pattern = '/(?:\\\\?_|\*){0,2}[ \t]*12345'.$sep.'*([a-z])'.$sep.'*(\d+)(?:[ \t]*(?:\\\\?_|\*)){0,2}/iu';

        $result = preg_replace_callback($pattern, function (array $m) use ($known): string {
            // Require some trace of the token syntax so ordinary text such as "12345 g 2" is left alone.
            if (! str_contains($m[0], '_')) {
                return $m[0];
            }

            $token = self::TOKEN_PREFIX.strtoupper($m[1]).'_'.(int) $m[2].self::TOKEN_SUFFIX;

            return array_key_exists($token, $known) ? $token : $m[0];
        }, $text);

        return is_string($result) ? $result : $text;
    }

    /**
     * Rule processor for CommonMark indented code blocks: a run of lines indented by 4+ spaces
     * or a tab that follows a blank line (or the start of the text), is not a list item and is
     * not continuing a list item. Each block becomes one token; its line breaks stay outside.
     *
     * @param array<int, string> $matches the whole text (the rule's pattern matches \A.*\z)
     */
    public static function protectIndentedCode(array $matches, Closure $protect): string
    {
        $lines = preg_split('/(?<=\n)/', $matches[0]);

        if ($lines === false) {
            return $matches[0];
        }

        $listMarker = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';
        $indented = fn (string $line) => preg_match('/^(?: {4}|[ \t]{0,3}\t)/', $line) === 1;
        $blank = fn (string $line) => trim($line) === '';

        $out = '';
        $count = count($lines);
        $prevBlank = true;
        $inList = false;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if ($blank($line)) {
                $out .= $line;
                $prevBlank = true;

                continue;
            }

            $isListItem = preg_match($listMarker, $line) === 1;

            if ($prevBlank && ! $inList && ! $isListItem && $indented($line)) {
                // Extend over indented and blank lines, then give trailing blank lines back.
                $end = $i;

                for ($j = $i + 1; $j < $count && ($indented($lines[$j]) || $blank($lines[$j])); $j++) {
                    if (! $blank($lines[$j])) {
                        $end = $j;
                    }
                }

                $block = implode('', array_slice($lines, $i, $end - $i + 1));
                preg_match('/\r?\n\z/', $block, $eol);
                $eol = $eol[0] ?? '';

                $out .= $protect(substr($block, 0, strlen($block) - strlen($eol))).$eol;
                $i = $end;
                $prevBlank = false;

                continue;
            }

            if ($isListItem) {
                $inList = true;
            } elseif ($prevBlank && ! preg_match('/^[ \t]/', $line)) {
                // An unindented paragraph after a blank line closes any open list.
                $inList = false;
            }

            $out .= $line;
            $prevBlank = false;
        }

        return $out;
    }
}
