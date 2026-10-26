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

use FoF\Translate\Content\ContentProtector;

/**
 * A text prepared by StructureMask, plus everything needed to prove that a translation of it
 * kept its structure and to undo the masking.
 *
 * Token kinds: `cp` (a ContentProtector token already in the request), `open`/`close`
 * (an emphasis delimiter pair), `marker` (list bullet/number or heading #'s), `break`
 * (trailing hard-break spaces). Glue sides say what to do with the space inserted there:
 * null (none inserted), `hard` (always remove), `punct` (the source neighbour was punctuation:
 * remove only if the translation's neighbour is punctuation too, so "value." reordered to
 * "value gespeichert" keeps its space) or `script` (the neighbour was CJK/Thai: remove only
 * when the target is written without spaces).
 */
final readonly class MaskedText
{
    public const TOKEN_PATTERN = '/__12345_[A-Z]_\d+__/';

    // Targets written without spaces between words.
    public const NO_SPACE_LANGUAGES = ['zh', 'ja', 'th', 'lo', 'km', 'my'];

    /**
     * @param string                                                                                                   $raw    tokens in place, no inserted spaces (segment mode works on this)
     * @param string                                                                                                   $text   what is sent to Google: $raw plus a space on each glued token side
     * @param array<string, array{kind: string, original: string|null, line: int, pair: string|null, glueLeft: string|null, glueRight: string|null}> $tokens
     * @param list<array{blank: bool, indent: string, leading: list<string>}>                                           $lines  per-line skeleton of $text
     */
    public function __construct(
        public string $raw,
        public string $text,
        public array $tokens,
        public array $lines,
    ) {
    }

    /**
     * @param array<string, array{kind: string, original: string|null, line: int, pair: string|null, glueLeft: string|null, glueRight: string|null}> $tokens
     */
    public static function build(string $raw, string $text, array $tokens): self
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            [$indent, $leading] = self::structure($line, $tokens);
            $lines[] = ['blank' => trim($line) === '', 'indent' => $indent, 'leading' => $leading];
        }

        return new self($raw, $text, $tokens, $lines);
    }

    /**
     * Why $translated does not preserve the structure, or null when it does.
     */
    public function failure(string $translated): ?string
    {
        $translated = $this->canonicalise($translated);
        $lines = explode("\n", $translated);

        if (count($lines) !== count($this->lines)) {
            return sprintf('line count changed from %d to %d', count($this->lines), count($lines));
        }

        /** @var array<string, array{int, int}> $seen token => [line, offset] */
        $seen = [];

        foreach ($lines as $n => $line) {
            preg_match_all(self::TOKEN_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$token, $offset]) {
                if (! isset($this->tokens[$token])) {
                    return "unexpected token $token";
                }

                if (isset($seen[$token])) {
                    return "token $token duplicated";
                }

                if ($this->tokens[$token]['line'] !== $n) {
                    return "token $token moved from line {$this->tokens[$token]['line']} to line $n";
                }

                $seen[$token] = [$n, $offset];
            }

            $expected = $this->lines[$n];

            if ((trim($line) === '') !== $expected['blank']) {
                return "line $n changed between blank and non-blank";
            }

            if (self::structure($line, $this->tokens) !== [$expected['indent'], $expected['leading']]) {
                return "line $n changed its indentation or leading markers";
            }
        }

        foreach ($this->tokens as $token => $meta) {
            if (! isset($seen[$token])) {
                return "token $token missing";
            }

            if ($meta['kind'] === 'open' && $meta['pair'] !== null && isset($seen[$meta['pair']])) {
                if ($seen[$token] > $seen[$meta['pair']]) {
                    return "emphasis $token now opens after its closing {$meta['pair']}";
                }

                // Google sometimes moves the words out and leaves the delimiters around a comma.
                if (self::hasLetters(self::between($this->text, $token, $meta['pair'])) && ! self::hasLetters(self::between($translated, $token, $meta['pair']))) {
                    return "emphasis $token lost its words";
                }
            }

            if ($meta['kind'] === 'break' && ! str_ends_with(rtrim($lines[$meta['line']], " \t"), $token)) {
                return "hard break $token no longer ends line {$meta['line']}";
            }
        }

        return null;
    }

    /**
     * Undo the masking of a translation that passed failure(): remove the spaces inserted on
     * glued sides and put the emphasis/marker/break originals back. ContentProtector tokens stay.
     */
    public function restore(string $translated, string $targetLanguage): string
    {
        $text = $this->canonicalise($translated);
        $noSpaceTarget = in_array(strtolower(substr($targetLanguage, 0, 2)), self::NO_SPACE_LANGUAGES, true);

        foreach ($this->tokens as $token => $meta) {
            $quoted = preg_quote($token, '/');

            if (($right = $this->closeUpPattern($meta['glueRight'], $noSpaceTarget)) !== null) {
                $text = preg_replace('/'.$quoted.'[ \t\x{00A0}]+'.$right.'/u', $token, $text) ?? $text;
            }

            if (($left = $this->closeUpPattern($meta['glueLeft'], $noSpaceTarget)) !== null) {
                $lookbehind = $left === '' ? '' : '(?<=[\p{P}\p{S}])';
                $text = preg_replace('/'.$lookbehind.'[ \t\x{00A0}]+'.$quoted.'/u', $token, $text) ?? $text;
            }
        }

        return $this->restoreRaw($text);
    }

    /**
     * Replace this mask's own tokens in text built from $raw (segment mode: no inserted spaces).
     */
    public function restoreRaw(string $raw): string
    {
        $originals = [];

        foreach ($this->tokens as $token => $meta) {
            if ($meta['original'] !== null) {
                $originals[$token] = $meta['original'];
            }
        }

        return strtr($raw, $originals);
    }

    /**
     * Null: leave the space. '': remove it. Otherwise a lookahead the new neighbour must satisfy.
     */
    protected function closeUpPattern(?string $glue, bool $noSpaceTarget): ?string
    {
        return match ($glue) {
            'hard' => '',
            'script' => $noSpaceTarget ? '' : null,
            'punct' => '(?=[\p{P}\p{S}])',
            default => null,
        };
    }

    protected static function between(string $text, string $open, string $close): string
    {
        $start = strpos($text, $open);
        $end = strpos($text, $close);

        return $start === false || $end === false || $end < $start ? '' : substr($text, $start + strlen($open), $end - $start - strlen($open));
    }

    protected static function hasLetters(string $text): bool
    {
        return preg_match('/\p{L}/u', (string) preg_replace(self::TOKEN_PATTERN, '', $text)) === 1;
    }

    protected function canonicalise(string $translated): string
    {
        return ContentProtector::canonicaliseTokens(str_replace("\r\n", "\n", $translated), $this->tokens);
    }

    /**
     * A line's indentation and the run of structural tokens (markers, ContentProtector tokens
     * such as blockquote prefixes) it starts with. Emphasis tokens end the run.
     *
     * @param array<string, array{kind: string, original: string|null, line: int, pair: string|null, glueLeft: string|null, glueRight: string|null}> $tokens
     * @return array{string, list<string>}
     */
    protected static function structure(string $line, array $tokens): array
    {
        preg_match('/^[ \t]*/', $line, $m);
        $indent = $m[0];
        $rest = substr($line, strlen($indent));
        $leading = [];

        while (preg_match('/^(__12345_[A-Z]_\d+__)[ \t]*/', $rest, $m)) {
            $kind = $tokens[$m[1]]['kind'] ?? null;

            if ($kind !== 'marker' && $kind !== 'cp') {
                break;
            }

            $leading[] = $m[1];
            $rest = substr($rest, strlen($m[0]));
        }

        return [$indent, $leading];
    }
}
