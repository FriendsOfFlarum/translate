<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\Translate\Detection\EldLanguageDetector;
use FoF\Translate\Detection\LanguageDetector;
use FoF\Translate\Detection\PollLanguage;
use FoF\Translate\Driver\ClaudeDriver;
use FoF\Translate\Driver\DeepLDriver;
use FoF\Translate\Driver\DriverManager;
use FoF\Translate\Driver\GoogleDriver;
use FoF\Translate\Driver\OllamaDriver;
use FoF\Translate\Driver\OpenAIDriver;

class TranslateServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Extended by Extend\TranslationDriver. Class names only: drivers are resolved
        // when a translation is requested, never on page loads.
        $this->container->singleton('fof-translate.drivers', fn () => [
            DeepLDriver::name() => DeepLDriver::class,
            OpenAIDriver::name() => OpenAIDriver::class,
            ClaudeDriver::name() => ClaudeDriver::class,
            OllamaDriver::name() => OllamaDriver::class,
            GoogleDriver::name() => GoogleDriver::class,
        ]);

        $this->container->singleton(DriverManager::class);

        // Singleton so ELD's n-gram database loads once per process.
        $this->container->singleton(LanguageDetector::class, EldLanguageDetector::class);

        // Singleton so its per-poll memo is shared within a request (entries die with the models).
        $this->container->singleton(PollLanguage::class);
    }
}
