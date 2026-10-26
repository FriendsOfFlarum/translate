<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use FoF\Translate\Content\ContentProtector;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Keep custom markup out of the translator's hands:
 *
 *     (new \FoF\Translate\Extend\TranslationProtection())
 *         ->protectBBCodeSelectively('callout', 'callout')
 *         ->protectSelfClosingBBCode('my-embed', 'embed')
 *
 * Rules run after the built-in ones (see ContentProtector::defaultRules()); registering an
 * existing name replaces that rule in place.
 */
class TranslationProtection implements ExtenderInterface
{
    /** @var array<string, array{pattern: string, type: string, processor: callable|null}> */
    protected array $protectionRules = [];

    /**
     * @param string        $type      'full' protects the whole match; 'selective' protects groups 1 and 3 and
     *                                 leaves group 2 translatable
     * @param callable|null $processor fn (array $matches, \Closure $protect): string — $protect(string) returns
     *                                 the placeholder for a span
     */
    public function addProtectionRule(string $name, string $pattern, string $type = 'full', ?callable $processor = null): self
    {
        if (! in_array($type, ['full', 'selective'], true)) {
            throw new InvalidArgumentException("Protection rule type must be 'full' or 'selective', '$type' given.");
        }

        $this->protectionRules[$name] = [
            'pattern' => $pattern,
            'type' => $type,
            'processor' => $processor,
        ];

        return $this;
    }

    /**
     * Protect the BBCode's opening and closing tags but translate its content.
     */
    public function protectBBCodeSelectively(string $name, string $tagName): self
    {
        return $this->addProtectionRule($name, ContentProtector::bbcodeTagPattern($tagName));
    }

    /**
     * Protect the BBCode tags and everything between them.
     */
    public function protectBBCodeFully(string $name, string $tagName): self
    {
        $tag = preg_quote($tagName, '/');

        return $this->addProtectionRule($name, "/\[{$tag}(?:[=\s][^\]]*)?\].*?\[\/{$tag}\]/is");
    }

    public function protectSelfClosingBBCode(string $name, string $tagName): self
    {
        $tag = preg_quote($tagName, '/');

        return $this->addProtectionRule($name, "/\[{$tag}(?:[=\s][^\]]*)?\]/i");
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        if ($this->protectionRules === []) {
            return;
        }

        $container->extend('fof-translate.protection-rules', function (array $rules) {
            return array_merge($rules, $this->protectionRules);
        });
    }
}
