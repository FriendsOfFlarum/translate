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

use GuzzleHttp\Exception\GuzzleException;
use Stichoza\GoogleTranslate\Exceptions\LargeTextException;
use Stichoza\GoogleTranslate\Exceptions\RateLimitException;
use Stichoza\GoogleTranslate\Exceptions\TranslationDecodingException;
use Stichoza\GoogleTranslate\Exceptions\TranslationRequestException;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Throwable;

/**
 * The stock getResponse() "repairs" Google's sparse JSON arrays with regexes over the whole
 * body (`,+` → `,`, `[,` → `[`, NBSP → space), which also rewrites the translated strings
 * ("a,, b" comes back as "a, b"). This decodes strictly first and only fills sparse array
 * slots outside string literals.
 */
class GoogleClient extends GoogleTranslate
{
    /**
     * @return array<int|string, mixed>
     */
    public function getResponse(string $string): array
    {
        $source = $this->source ?? 'auto';
        $target = $this->target ?? 'en';

        $query = array_merge($this->urlParams, [
            'sl' => $source,
            'tl' => $target,
            'tk' => $this->tokenProvider->generateToken($source, $target, $string),
            'q' => $string,
        ]);

        // Repeated keys ("dt=t&dt=bd"), as the upstream client sends them.
        $queryUrl = (string) preg_replace('/%5B\d+%5D=/', '=', http_build_query($query));

        try {
            $response = $this->client->get($this->url, ['query' => $queryUrl] + $this->options);
        } catch (GuzzleException $e) {
            throw match ($e->getCode()) {
                429, 503 => new RateLimitException($e->getMessage(), $e->getCode()),
                413 => new LargeTextException($e->getMessage(), $e->getCode()),
                default => new TranslationRequestException($e->getMessage(), $e->getCode()),
            };
        } catch (Throwable $e) {
            throw new TranslationRequestException($e->getMessage(), (int) $e->getCode());
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            $decoded = json_decode(self::fillSparseArrays($body), true);
        }

        if (! is_array($decoded)) {
            throw new TranslationDecodingException('Google returned a response that is not JSON.');
        }

        return $decoded;
    }

    /**
     * Turns JavaScript-style holes ("[,,1]") into nulls, never touching string contents.
     */
    public static function fillSparseArrays(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $previous = '';
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                $out .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif (($char === ',' || $char === ']') && ($previous === '[' || $previous === ',') && ! ($char === ']' && $previous === '[')) {
                $out .= 'null';
            }

            $out .= $char;

            if (! ctype_space($char)) {
                $previous = $char;
            }
        }

        return $out;
    }
}
