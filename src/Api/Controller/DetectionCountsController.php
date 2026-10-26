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

use Flarum\Http\RequestUtil;
use FoF\Translate\Detection\DetectionRunner;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin-only: `GET /api/fof-translate/detection`
 * => {"data": {"posts": {"total", "pending"}, "discussions": {"total", "pending"}}}.
 */
class DetectionCountsController implements RequestHandlerInterface
{
    public function __construct(
        protected DetectionRunner $runner,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => $this->runner->counts()]);
    }
}
