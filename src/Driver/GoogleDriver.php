<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver;

use FoF\Translate\Driver\Google\GoogleClient;
use FoF\Translate\Driver\Google\SafeTranslator;
use FoF\Translate\Driver\Google\StructureMask;
use Psr\Log\LoggerInterface;
use Stichoza\GoogleTranslate\Exceptions\LanguagesRequestException;
use Stichoza\GoogleTranslate\Exceptions\LargeTextException;
use Stichoza\GoogleTranslate\Exceptions\RateLimitException;
use Stichoza\GoogleTranslate\Exceptions\TranslationDecodingException;
use Stichoza\GoogleTranslate\Exceptions\TranslationRequestException;
use Throwable;

/**
 * Free Google Translate (the public web client via stichoza/google-translate-php). No key,
 * no SLA, and Google may block a server at any time. Google mangles Markdown structure, so every
 * response goes through SafeTranslator, which only ever returns structurally verified text.
 */
class GoogleDriver implements BatchTranslationDriver
{
    public const TIMEOUT = 20;

    // Codes Flarum locales use that Google spells differently.
    protected const GOOGLE_CODES = ['zh' => 'zh-CN', 'nb' => 'no', 'jv' => 'jw'];
    protected const FROM_GOOGLE_CODES = ['iw' => 'he', 'jw' => 'jv', 'no' => 'nb'];

    protected ?GoogleClient $client = null;

    public function __construct(
        protected LoggerInterface $logger,
    ) {
    }

    public static function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $translator = $this->safeTranslator($request);

        try {
            [$text, $detected] = $translator->translate($request->text);
        } finally {
            $this->logStats($translator);
        }

        return new TranslationResult($text, $this->normaliseLanguage($detected));
    }

    /**
     * Single-line strings go out newline-joined in one verified request; if that fails
     * verification (or a string is multi-line) each string is translated on its own.
     */
    public function translateBatch(array $requests): array
    {
        $requests = array_values($requests);
        $results = [];
        $joinable = [];

        foreach ($requests as $i => $request) {
            if (! StructureMask::hasTranslatableText($request->text)) {
                $results[$i] = new TranslationResult($request->text);
            } elseif (! str_contains($request->text, "\n") && ! str_contains($request->text, "\r")) {
                $joinable[$i] = $request;
            }
        }

        if (count($joinable) > 1) {
            $translator = $this->safeTranslator($joinable[array_key_first($joinable)]);
            $joined = $translator->attempt(implode("\n", array_map(fn (TranslationRequest $r) => trim($r->text), $joinable)));
            $this->logStats($translator);

            $lines = $joined === null ? [] : explode("\n", $joined);

            if (count($lines) === count($joinable)) {
                $detected = $this->normaliseLanguage($translator->detectedLanguage());

                foreach (array_keys($joinable) as $n => $i) {
                    preg_match('/^(\s*).*?(\s*)\z/su', $joinable[$i]->text, $outer);
                    $results[$i] = new TranslationResult($outer[1].$lines[$n].$outer[2], $detected);
                }
            }
        }

        foreach ($requests as $i => $request) {
            $results[$i] ??= $this->translate($request);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @return list<string>
     */
    public function targetLanguages(): array
    {
        // The language list is scraped from an HTML page; keep libxml's HTML5 warnings out of the error handler.
        $previous = libxml_use_internal_errors(true);

        try {
            $languages = $this->client()->languages();
        } catch (RateLimitException $e) {
            throw new TranslationFailedException('Google blocked or rate-limited this server: '.$e->getMessage(), 0, $e);
        } catch (LanguagesRequestException $e) {
            throw new TranslationFailedException('Google language list request failed: '.$e->getMessage(), 0, $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $codes = [];

        foreach ($languages as $code) {
            $code = $this->normaliseLanguage($code);

            if ($code !== null) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    protected function safeTranslator(TranslationRequest $request): SafeTranslator
    {
        $target = strtolower($request->targetLanguage);
        $source = $request->sourceLanguage !== null ? strtolower($request->sourceLanguage) : null;

        return new SafeTranslator(fn (string $text) => $this->request($text, $target, $source), $target);
    }

    /**
     * One HTTP request to Google. Overridden in tests to script responses.
     *
     * @return array{0: string, 1: string|null} translation and the source language Google detected
     *
     * @throws TranslationFailedException
     */
    protected function request(string $text, string $target, ?string $source): array
    {
        $client = $this->client()
            ->setTarget(self::GOOGLE_CODES[$target] ?? $target)
            ->setSource($source !== null ? (self::GOOGLE_CODES[$source] ?? $source) : null);

        try {
            $translated = $client->translate($text);
        } catch (RateLimitException $e) {
            throw new TranslationFailedException('Google blocked or rate-limited this server (HTTP '.$e->getCode().').', 0, $e);
        } catch (LargeTextException $e) {
            throw new TranslationFailedException('Google rejected the text as too large.', 0, $e);
        } catch (TranslationDecodingException $e) {
            throw new TranslationFailedException('Google returned an unreadable response (it may have blocked this server): '.$e->getMessage(), 0, $e);
        } catch (TranslationRequestException $e) {
            throw new TranslationFailedException('Google Translate request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new TranslationFailedException('Google Translate request failed: '.$e::class.': '.$e->getMessage(), 0, $e);
        }

        if ($translated === null) {
            throw new TranslationFailedException('Google returned no translation.');
        }

        return [$translated, $client->getLastDetectedSource()];
    }

    protected function client(): GoogleClient
    {
        // Leave the `client` param to the library: Google answers every `gtx` request with 429 (Stichoza/google-translate-php#225).
        return $this->client ??= new GoogleClient(options: ['timeout' => self::TIMEOUT, 'connect_timeout' => 10]);
    }

    protected function normaliseLanguage(?string $code): ?string
    {
        // "zh-CN" → "zh", "iw" → "he"; "auto", "fil", "mni-Mtei" have no 2-letter code.
        if (! preg_match('/^([a-z]{2,8})(?:[-_][a-z0-9]{2,8})*$/', strtolower(trim((string) $code)), $m)) {
            return null;
        }

        $primary = self::FROM_GOOGLE_CODES[$m[1]] ?? $m[1];

        return strlen($primary) === 2 ? $primary : null;
    }

    protected function logStats(SafeTranslator $translator): void
    {
        $stats = $translator->stats();

        if ($stats['rejected'] > 0) {
            $this->logger->debug(sprintf(
                '[fof/translate] google: %d response(s) failed the structure check (%s); %d request(s), %d segment fallback(s).',
                $stats['rejected'],
                implode('; ', array_unique($stats['reasons'])),
                $stats['requests'],
                $stats['segment_fallbacks']
            ));
        }
    }
}
