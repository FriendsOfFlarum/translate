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

use Flarum\Frontend\Document;
use FoF\Translate\Driver\DriverManager;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `fof-translate.drivers` => [name => {name, configured, fields}] for the admin driver
 * picker. Static data only: isConfigured() and settingsFields() never touch the network.
 */
class AdminPayload
{
    public function __construct(
        protected DriverManager $drivers,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $payload = [];

        foreach (array_keys($this->drivers->registered()) as $name) {
            $driver = $this->drivers->make($name);

            $payload[$name] = [
                'name' => $name,
                'configured' => $driver->isConfigured(),
                'fields' => $driver->settingsFields(),
            ];
        }

        $document->payload['fof-translate.drivers'] = $payload;
    }
}
