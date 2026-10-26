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

use Closure;
use FoF\Translate\Driver\TranslationFailedException;

/**
 * Translates one text through a free machine translator without ever returning text whose
 * structure was changed. Each chunk is masked (StructureMask) and the response verified
 * (MaskedText::failure()); on failure it retries paragraph by paragraph, and a paragraph that
 * still fails goes to segment mode, where only the plain-text runs between tokens are sent
 * and the structure is rebuilt from the source. Exceeding the request budget fails closed.
 *
 * One instance per translation: it carries the request budget and the detected language.
 */
class SafeTranslator
{
    // Google's documented-by-behaviour limit is ~5000 characters per request.
    public const MAX_CHUNK = 4500;
    public const MAX_REQUESTS = 25;

    protected int $requests = 0;
    protected ?string $detected = null;

    /** @var array{requests: int, verified: int, rejected: int, paragraph_fallbacks: int, segment_fallbacks: int, run_fallbacks: int, reasons: list<string>} */
    protected array $stats = [
        'requests' => 0,
        'verified' => 0,
        'rejected' => 0,
        'paragraph_fallbacks' => 0,
        'segment_fallbacks' => 0,
        'run_fallbacks' => 0,
        'reasons' => [],
    ];

    /**
     * @param Closure(string): array{0: string, 1: string|null} $transport one HTTP request: [translation, detected source]
     */
    public function __construct(
        protected Closure $transport,
        protected string $targetLanguage,
        protected int $maxRequests = self::MAX_REQUESTS,
        protected int $maxChunk = self::MAX_CHUNK,
    ) {
    }

    /**
     * @return array{0: string, 1: string|null} translation and the detected source language
     *
     * @throws TranslationFailedException
     */
    public function translate(string $text): array
    {
        $crlf = str_contains($text, "\r\n");
        $text = $crlf ? str_replace("\r\n", "\n", $text) : $text;
        $out = '';

        foreach ($this->chunks($text) as $chunk) {
            $parts = $this->splitOuterWhitespace($chunk);
            $out .= $parts === null ? $chunk : $parts[0].$this->translateChunk($parts[1]).$parts[2];
        }

        return [$crlf ? str_replace("\n", "\r\n", $out) : $out, $this->detected];
    }

    /**
     * A single masked request with no fallback: the verified translation, or null.
     *
     * @throws TranslationFailedException on transport failure or an exhausted budget
     */
    public function attempt(string $text): ?string
    {
        if (str_contains($text, "\r") || mb_strlen($text) > $this->maxChunk) {
            return null;
        }

        $parts = $this->splitOuterWhitespace($text);

        if ($parts === null) {
            return $text;
        }

        $result = $this->tryMask(StructureMask::mask($parts[1]));

        return $result === null ? null : $parts[0].$result.$parts[2];
    }

    public function detectedLanguage(): ?string
    {
        return $this->detected;
    }

    /**
     * @return array{requests: int, verified: int, rejected: int, paragraph_fallbacks: int, segment_fallbacks: int, run_fallbacks: int, reasons: list<string>}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    protected function translateChunk(string $core): string
    {
        $mask = StructureMask::mask($core);

        if (($result = $this->tryMask($mask)) !== null) {
            return $result;
        }

        $parts = preg_split('/(\n[ \t]*\n(?:[ \t]*\n)*)/', $core, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$core];

        if (count($parts) === 1) {
            return $this->segmentMode($mask);
        }

        $this->stats['paragraph_fallbacks']++;
        $out = '';

        foreach ($parts as $i => $part) {
            // Odd indexes are the captured blank-line separators.
            $paragraph = $i % 2 === 0 ? $this->splitOuterWhitespace($part) : null;

            if ($paragraph === null) {
                $out .= $part;

                continue;
            }

            $mask = StructureMask::mask($paragraph[1]);
            $out .= $paragraph[0].($this->tryMask($mask) ?? $this->segmentMode($mask)).$paragraph[2];
        }

        return $out;
    }

    protected function tryMask(MaskedText $mask): ?string
    {
        [$translated] = $this->call($mask->text);

        if (($reason = $mask->failure($translated)) !== null) {
            $this->stats['rejected']++;
            $this->stats['reasons'][] = $reason;

            return null;
        }

        $this->stats['verified']++;

        return $mask->restore($translated, $this->targetLanguage);
    }

    /**
     * Sends only the text between tokens and line breaks; everything else is copied from the
     * source, so the structure is identical by construction.
     */
    protected function segmentMode(MaskedText $mask): string
    {
        $this->stats['segment_fallbacks']++;

        $parts = preg_split('/(__12345_[A-Z]_\d+__|\n)/', $mask->raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$mask->raw];

        /** @var array<int, array{string, string, string}> $runs part index => [leading ws, core, trailing ws] */
        $runs = [];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 && StructureMask::hasTranslatableText($part) && preg_match('/^(\s*)(.*?)(\s*)\z/su', $part, $m)) {
                $runs[$i] = [$m[1], $m[2], $m[3]];
            }
        }

        if ($runs !== []) {
            $translations = $this->translateRuns(array_column($runs, 1));

            foreach (array_keys($runs) as $n => $i) {
                $parts[$i] = $runs[$i][0].$translations[$n].$runs[$i][2];
            }
        }

        return $mask->restoreRaw(implode('', $parts));
    }

    /**
     * @param list<string> $cores single-line, trimmed, non-empty
     * @return list<string>
     */
    protected function translateRuns(array $cores): array
    {
        if (count($cores) > 1) {
            [$joined] = $this->call(implode("\n", $cores));
            $lines = explode("\n", str_replace("\r\n", "\n", trim($joined, "\n")));

            if (count($lines) === count($cores) && ! in_array('', array_map('trim', $lines), true)) {
                return array_map('trim', $lines);
            }

            $this->stats['run_fallbacks']++;
        }

        $out = [];

        foreach ($cores as $core) {
            [$translated] = $this->call($core);
            $translated = trim((string) preg_replace('/\s*\n\s*/', ' ', $translated));

            // An empty answer keeps the source run: untranslated, but never lost.
            $out[] = $translated !== '' ? $translated : $core;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function call(string $text): array
    {
        if ($this->requests >= $this->maxRequests) {
            throw new TranslationFailedException(sprintf(
                'Google translation needed more than %d requests to keep the formatting intact; giving up rather than returning unverified text.',
                $this->maxRequests
            ));
        }

        $this->requests++;
        $this->stats['requests']++;

        [$translated, $detected] = ($this->transport)($text);
        $this->detected ??= $detected;

        return [$translated, $detected];
    }

    /**
     * [leading whitespace, core, trailing whitespace] — Google trims outer whitespace, so only the
     * core is sent. Null when there is nothing to translate (only tokens, numbers, punctuation).
     *
     * @return array{string, string, string}|null
     */
    protected function splitOuterWhitespace(string $text): ?array
    {
        if (! StructureMask::hasTranslatableText($text) || ! preg_match('/^(\s*)(.*?)(\s*)\z/su', $text, $m)) {
            return null;
        }

        return [$m[1], $m[2], $m[3]];
    }

    /**
     * Splits at paragraph, then line, then word boundaries so every chunk fits one request.
     * Concatenating the chunks gives back the input exactly.
     *
     * @return list<string>
     */
    protected function chunks(string $text): array
    {
        if (mb_strlen($text) <= $this->maxChunk) {
            return [$text];
        }

        return $this->pack($text, ['/(?<=\n\n)/', '/(?<=\n)/', '/(?<=\s)(?=\S)/u']);
    }

    /**
     * @param list<string> $splitters
     * @return list<string>
     */
    protected function pack(string $text, array $splitters): array
    {
        $splitter = array_shift($splitters);
        $pieces = $splitter === null
            ? mb_str_split($text, $this->maxChunk)
            : (preg_split($splitter, $text) ?: [$text]);

        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if (mb_strlen($piece) > $this->maxChunk) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                array_push($chunks, ...$this->pack($piece, $splitters));

                continue;
            }

            if ($current !== '' && mb_strlen($current.$piece) > $this->maxChunk) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $piece;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
