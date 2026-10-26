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

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;

class ContentServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Extended by Extend\TranslationProtection.
        $this->container->singleton('fof-translate.protection-rules', fn () => ContentProtector::defaultRules());

        $this->container->singleton(
            ContentProtector::class,
            fn (Container $container) => new ContentProtector($container->make('fof-translate.protection-rules'))
        );
    }
}
