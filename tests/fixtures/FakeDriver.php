<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\fixtures;

use FoF\Translate\Driver\TranslationDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use FoF\Translate\Driver\TranslationResult;

/**
 * Network-free driver. State is static: integration tests run in separate processes.
 */
class FakeDriver implements TranslationDriver
{
    /** @var list<TranslationRequest> */
    public static array $requests = [];

    public static ?string $detected = null;

    public static bool $fail = false;

    /** Return the text untouched, like a provider handed a single protected token. */
    public static bool $echo = false;

    public static int $constructed = 0;

    public static int $targetCalls = 0;

    public static bool $targetsFail = false;

    public function __construct()
    {
        static::$constructed++;
    }

    /** @var list<string> */
    public static array $targets = ['en', 'de', 'fr'];

    public static function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        static::$requests[] = $request;

        if (static::$fail) {
            throw new TranslationFailedException('provider exploded');
        }

        return new TranslationResult(static::$echo ? $request->text : '['.$request->targetLanguage.'] '.$request->text, static::$detected);
    }

    public function targetLanguages(): array
    {
        static::$targetCalls++;

        if (static::$targetsFail) {
            throw new TranslationFailedException('listing exploded');
        }

        return static::$targets;
    }

    public function settingsFields(): array
    {
        return ['model' => ['type' => 'string', 'required' => true]];
    }
}
