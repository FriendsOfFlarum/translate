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

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use FoF\Translate\Detection\DetectionRunner;
use FoF\Translate\Event\LanguageDetectionRun;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin-only: `POST /api/fof-translate/detection` with {"type": "posts"|"discussions",
 * "force": bool, "after": int|null} runs one time-boxed batch and returns
 * {"data": {"processed", "detected", "after", "done"}}. The admin page calls it again
 * with the returned `after` until `done`, so no request runs longer than the budget.
 */
class RunDetectionController implements RequestHandlerInterface
{
    public const LIMIT = 1000;
    public const TIME_BUDGET = 3.0;

    public function __construct(
        protected DetectionRunner $runner,
        protected TranslatorInterface $translator,
        protected Dispatcher $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $type = Arr::get($body, 'type');
        $after = Arr::get($body, 'after');

        if (! is_string($type) || ! in_array($type, DetectionRunner::TYPES, true)) {
            throw new ValidationException(['type' => $this->translator->trans('fof-translate.api.invalid_detection_type')]);
        }

        if ($after !== null && filter_var($after, FILTER_VALIDATE_INT) === false) {
            throw new ValidationException(['after' => $this->translator->trans('fof-translate.api.invalid_detection_cursor')]);
        }

        $force = filter_var(Arr::get($body, 'force', false), FILTER_VALIDATE_BOOLEAN);

        // The admin panel runs detection as a series of batches; a run starts with no cursor.
        // Counted before the first batch, while "pending" still includes the rows it will process.
        if ($after === null) {
            $counts = $this->runner->counts()[$type];

            $this->events->dispatch(new LanguageDetectionRun($type, $force, $force ? $counts['total'] : $counts['pending'], 'admin'));
        }

        return new JsonResponse(['data' => $this->runner->batch(
            $type,
            $force,
            $after === null ? null : (int) $after,
            self::LIMIT,
            self::TIME_BUDGET,
        )]);
    }
}
