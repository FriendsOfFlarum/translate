<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver\Google;

/**
 * Pre-pass for Google Translate: hides the Markdown structure Google is known to damage
 * (emphasis delimiters, list/heading markers, hard-break spaces) behind tokens in the
 * ContentProtector format with kind S — `__12345_S_{n}__` — numbered after any S tokens
 * already present, so they never collide with the request's own tokens.
 *
 * Input must use "\n" line endings.
 */
class StructureMask
{
    // Byte fillers for the scan copy: offsets stay aligned with the real text.
    protected const FILL_TOKEN = "\x02";
    protected const FILL_MARKER = ' ';

    protected const NO_SPACE_SCRIPT = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

    public static function hasTranslatableText(string $text): bool
    {
        return preg_match('/\p{L}/u', (string) preg_replace(MaskedText::TOKEN_PATTERN, '', $text)) === 1;
    }

    public static function mask(string $text): MaskedText
    {
        preg_match_all(MaskedText::TOKEN_PATTERN, $text, $existing, PREG_OFFSET_CAPTURE);

        $next = 0;
        $scan = $text;

        foreach ($existing[0] as [$token, $offset]) {
            if (preg_match('/^__12345_S_(\d+)__$/', $token, $m)) {
                $next = max($next, (int) $m[1] + 1);
            }

            $scan = substr_replace($scan, str_repeat(self::FILL_TOKEN, strlen($token)), $offset, strlen($token));
        }

        /** @var array<int, array{length: int, kind: string, original: string, pair?: int}> $spans offset => span */
        $spans = [];

        self::findLineStructure($text, $scan, $spans);
        self::findEmphasis($scan, $spans);

        ksort($spans);

        return self::assemble($text, $spans, $next);
    }

    /**
     * List bullets/numbers and heading markers at line start (after indentation and any leading
     * ContentProtector tokens such as blockquote prefixes), and trailing hard-break spaces.
     *
     * @param array<int, array{length: int, kind: string, original: string, pair?: int}> $spans
     */
    protected static function findLineStructure(string $text, string &$scan, array &$spans): void
    {
        $offset = 0;
        $lines = explode("\n", $scan);
        $last = count($lines) - 1;
        $fill = preg_quote(self::FILL_TOKEN, '/');

        foreach ($lines as $n => $line) {
            $markerEnd = 0;

            if (preg_match('/^([ \t]*(?:'.$fill.'+[ \t]*)*)((?:[-*+]|\d{1,9}[.)])[ \t]+|#{1,6}(?:[ \t]+|$))/', $line, $m)) {
                $start = $offset + strlen($m[1]);
                $markerEnd = strlen($m[0]);
                $spans[$start] = ['length' => strlen($m[2]), 'kind' => 'marker', 'original' => substr($text, $start, strlen($m[2]))];
                $scan = substr_replace($scan, str_repeat(self::FILL_MARKER, strlen($m[2])), $start, strlen($m[2]));
            }

            if ($n < $last && preg_match('/(?<=[^ \t])( {2,})$/', $line, $m, PREG_OFFSET_CAPTURE) && $m[1][1] >= $markerEnd) {
                $start = $offset + $m[1][1];
                $spans[$start] = ['length' => strlen($m[1][0]), 'kind' => 'break', 'original' => $m[1][0]];
            }

            $offset += strlen($line) + 1;
        }
    }

    /**
     * CommonMark-style delimiter runs (`*`, `_`, `~~`): only runs that can open or close by the
     * flanking rules, and only matched pairs within a paragraph, are masked. Intraword runs and
     * lone delimiters stay as text.
     *
     * @param array<int, array{length: int, kind: string, original: string, pair?: int}> $spans
     */
    protected static function findEmphasis(string $scan, array &$spans): void
    {
        preg_match_all('/\*+|_+|~~+/', $scan, $runs, PREG_OFFSET_CAPTURE);

        /** @var array<string, list<int>> $openers delimiter => offsets */
        $openers = [];
        $previousEnd = 0;

        foreach ($runs[0] as [$run, $start]) {
            if (preg_match('/\n[ \t]*\n/', substr($scan, $previousEnd, $start - $previousEnd))) {
                $openers = [];
            }

            $previousEnd = $start + strlen($run);

            $before = self::charBefore($scan, $start);
            $after = self::charAfter($scan, $previousEnd);

            $beforeSpace = self::isSpace($before);
            $afterSpace = self::isSpace($after);
            $beforePunct = self::isPunctuation($before);
            $afterPunct = self::isPunctuation($after);

            $left = ! $afterSpace && (! $afterPunct || $beforeSpace || $beforePunct);
            $right = ! $beforeSpace && (! $beforePunct || $afterSpace || $afterPunct);

            if ($left && $right && ! $beforePunct && ! $afterPunct) {
                continue;
            }

            if ($run[0] === '_') {
                $canOpen = $left && (! $right || $beforePunct);
                $canClose = $right && (! $left || $afterPunct);
            } else {
                $canOpen = $left;
                $canClose = $right;
            }

            if ($canClose && ! empty($openers[$run])) {
                $open = array_pop($openers[$run]);
                $spans[$open] = ['length' => strlen($run), 'kind' => 'open', 'original' => $run, 'pair' => $start];
                $spans[$start] = ['length' => strlen($run), 'kind' => 'close', 'original' => $run];
            } elseif ($canOpen) {
                $openers[$run][] = $start;
            }
        }
    }

    /**
     * @param array<int, array{length: int, kind: string, original: string, pair?: int}> $spans sorted by offset
     */
    protected static function assemble(string $text, array $spans, int $next): MaskedText
    {
        /** @var array<int, string> $names span offset => token */
        $names = [];

        foreach (array_keys($spans) as $offset) {
            $names[$offset] = '__12345_S_'.$next++.'__';
        }

        $raw = '';
        $cursor = 0;

        foreach ($spans as $offset => $span) {
            $raw .= substr($text, $cursor, $offset - $cursor).$names[$offset];
            $cursor = $offset + $span['length'];
        }

        $raw .= substr($text, $cursor);

        /** @var array<string, array{kind: string, original: string|null, pair: string|null}> $meta */
        $meta = [];

        foreach ($spans as $offset => $span) {
            $meta[$names[$offset]] = [
                'kind' => $span['kind'],
                'original' => $span['original'],
                'pair' => isset($span['pair']) ? $names[$span['pair']] : null,
            ];
        }

        return self::withGlue($raw, $meta);
    }

    /**
     * Inserts a space wherever a token touches non-whitespace, so Google sees the neighbouring
     * words as words, and records which sides to close up again on restore. S tokens are only
     * glued on their inner side (an emphasis opener to the text it opens, and so on); two
     * touching tokens always close up again.
     *
     * @param array<string, array{kind: string, original: string|null, pair: string|null}> $meta
     */
    protected static function withGlue(string $raw, array $meta): MaskedText
    {
        preg_match_all(MaskedText::TOKEN_PATTERN, $raw, $found, PREG_OFFSET_CAPTURE);

        $tokens = [];
        $text = '';
        $cursor = 0;

        foreach ($found[0] as $n => [$token, $offset]) {
            $end = $offset + strlen($token);
            $kind = $meta[$token]['kind'] ?? 'cp';
            $cp = $kind === 'cp';

            $previousEnd = $n > 0 ? $found[0][$n - 1][1] + strlen($found[0][$n - 1][0]) : -1;
            $nextStart = $found[0][$n + 1][1] ?? -1;

            $glueLeft = $cp || in_array($kind, ['close', 'break'], true)
                ? self::glue(self::charBefore($raw, $offset), $cp && $previousEnd !== $offset)
                : null;
            $glueRight = $cp || in_array($kind, ['open', 'marker'], true)
                ? self::glue(self::charAfter($raw, $end), $cp && $nextStart !== $end)
                : null;

            $text .= substr($raw, $cursor, $offset - $cursor);

            if ($glueLeft !== null && ! str_ends_with($text, ' ')) {
                $text .= ' ';
            }

            $tokens[$token] = [
                'kind' => $kind,
                'original' => $meta[$token]['original'] ?? null,
                'line' => substr_count($text, "\n"),
                'pair' => $meta[$token]['pair'] ?? null,
                'glueLeft' => $glueLeft,
                'glueRight' => $glueRight,
            ];

            // A following token glued to this one sees the space and does not add another.
            $text .= $token.($glueRight !== null ? ' ' : '');
            $cursor = $end;
        }

        $text .= substr($raw, $cursor);

        return MaskedText::build($raw, $text, $tokens);
    }

    /**
     * S tokens always hug their inner side (an opener with a space after it no longer opens).
     * A ContentProtector token touching punctuation or CJK text only closes up again when the
     * translation puts it next to punctuation, or the target is written without spaces.
     */
    protected static function glue(?string $neighbour, bool $classify): ?string
    {
        if ($neighbour === null || self::isSpace($neighbour)) {
            return null;
        }

        if (! $classify) {
            return 'hard';
        }

        if (preg_match(self::NO_SPACE_SCRIPT, $neighbour)) {
            return 'script';
        }

        return preg_match('/^[\p{P}\p{S}]$/u', $neighbour) ? 'punct' : 'hard';
    }

    protected static function charBefore(string $text, int $offset): ?string
    {
        if ($offset <= 0) {
            return null;
        }

        $chunk = substr($text, max(0, $offset - 4), min(4, $offset));

        return mb_substr($chunk, -1, 1, 'UTF-8');
    }

    protected static function charAfter(string $text, int $offset): ?string
    {
        if ($offset >= strlen($text)) {
            return null;
        }

        return mb_substr(substr($text, $offset, 4), 0, 1, 'UTF-8');
    }

    protected static function isSpace(?string $char): bool
    {
        return $char === null || preg_match('/^\s$/u', $char) === 1;
    }

    // Masked ContentProtector tokens count as punctuation: they stand for code, links or markup.
    protected static function isPunctuation(?string $char): bool
    {
        return $char !== null && ($char === self::FILL_TOKEN || preg_match('/^[\p{P}\p{S}]$/u', $char) === 1);
    }
}
