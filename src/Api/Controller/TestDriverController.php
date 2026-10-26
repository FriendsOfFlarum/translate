<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Api\Controller;

use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use FoF\Translate\Driver\DriverManager;
use FoF\Translate\Driver\DriverTester;
use FoF\Translate\Event\DriverTested;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin-only: `POST /api/fof-translate/drivers/{name}/test` with an optional
 * {"settings": {...unsaved form values...}}. Always 200 once authorized and the driver
 * exists; success or failure is reported in `data.ok` / `data.error`.
 */
class TestDriverController implements RequestHandlerInterface
{
    public function __construct(
        protected DriverManager $drivers,
        protected DriverTester $tester,
        protected TranslatorInterface $translator,
        protected Dispatcher $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $name = (string) Arr::get($request->getQueryParams(), 'name');

        if (! isset($this->drivers->registered()[$name])) {
            throw new RouteNotFoundException();
        }

        $settings = Arr::get((array) $request->getParsedBody(), 'settings', []);

        $result = $this->tester->test(
            $name,
            is_array($settings) ? $settings : [],
            $this->translator->getLocale(),
            $actor,
            $request,
        );

        $this->events->dispatch(new DriverTested($name, (bool) ($result['ok'] ?? false), $actor));

        return new JsonResponse(['data' => $result]);
    }
}
