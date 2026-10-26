<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Detection;

/**
 * Reduces unparsed post source to the prose a language detector should see. Code,
 * URLs, mentions and markup carry no (or misleading) language signal.
 */
class PlainText
{
    /**
     * Prose of stored s9e XML without running the formatter's unparse callbacks (which
     * may query, e.g. mentions refreshing display names): markup markers (<s>, <e>, <i>),
     * code, mentions and images are dropped, entities decoded.
     */
    public static function fromXml(?string $xml): string
    {
        $xml = (string) $xml;

        if ($xml === '' || $xml[0] !== '<') {
            return static::from($xml);
        }

        $xml = preg_replace([
            '#<(s|e|i)>.*?</\1>#s',
            '#<(CODE|C|POSTMENTION|USERMENTION|TAGMENTION|GROUPMENTION|EMOJI|IMG|UPL-IMAGE-PREVIEW)\b[^>]*?(/>|>.*?</\1>)#s',
        ], ' ', $xml) ?? $xml;

        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return static::from($text);
    }

    public static function from(string $text): string
    {
        $patterns = [
            '/```.*?(```|$)/s' => ' ',                          // fenced code
            '/\[code[^\]]*\].*?(\[\/code\]|$)/is' => ' ',      // BBCode code
            '/`[^`\n]*`/' => ' ',                              // inline code
            '/!\[[^\]]*\]\([^)]*\)/' => ' ',                   // images
            '/\[([^\]]*)\]\([^)]*\)/' => '$1',                 // markdown links keep their text
            '/\[\/?[a-z][a-z0-9_*-]*(=[^\]]*)?[^\]]*\]/i' => ' ', // BBCode tags
            '/<[^>]+>/' => ' ',                                // HTML tags
            '/\bhttps?:\/\/\S+|\bwww\.\S+/i' => ' ',           // URLs
            '/@"[^"]*"#[a-z]?\d+|@[\w.-]+/u' => ' ',           // mentions
            '/[#*_>~|=\-]{1,}/' => ' ',                        // markdown punctuation
        ];

        $text = preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
