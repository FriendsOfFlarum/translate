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
use FoF\Translate\Driver\TranslationDriver as DriverContract;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Register additional translation drivers:
 *
 *     (new \FoF\Translate\Extend\TranslationDriver())->add(MyDriver::class)
 */
class TranslationDriver implements ExtenderInterface
{
    /** @var list<class-string<DriverContract>> */
    protected array $drivers = [];

    /**
     * @param class-string<DriverContract> $driver
     */
    public function add(string $driver): self
    {
        if (! is_subclass_of($driver, DriverContract::class)) {
            throw new InvalidArgumentException("$driver must implement ".DriverContract::class);
        }

        $this->drivers[] = $driver;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('fof-translate.drivers', function (array $drivers) {
            foreach ($this->drivers as $driver) {
                $drivers[$driver::name()] = $driver;
            }

            return $drivers;
        });
    }
}
