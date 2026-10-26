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
use Illuminate\Contracts\Cache\Repository as Cache;
use FoF\Translate\Driver\DeepLDriver;
use FoF\Translate\Driver\DriverManager;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Admin-only: the DeepL glossaries available to the configured key, grouped by
 * language pair (`{src}_to_{tgt}` => [glossaryId => label]). Cached for 10 minutes per
 * DeepL settings fingerprint (which hashes no secrets) so admin page visits do not
 * call DeepL every time; failures are not cached.
 */
class ListDeepLGlossariesController implements RequestHandlerInterface
{
    public const CACHE_TTL = 600;

    public function __construct(
        protected Cache $cache,
        protected DriverManager $drivers,
        protected LoggerInterface $logger,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $name = DeepLDriver::name();

        if (! isset($this->drivers->registered()[$name])) {
            return new JsonResponse(['data' => []]);
        }

        $driver = $this->drivers->make($name);

        if (! $driver instanceof DeepLDriver || ! $driver->isConfigured()) {
            return new JsonResponse(['data' => []]);
        }

        try {
            $glossaries = $this->cache->remember(
                'fof-translate.deepl-glossaries.'.$this->drivers->fingerprint($name),
                self::CACHE_TTL,
                fn () => $driver->listGlossaries()
            );

            return new JsonResponse(['data' => $glossaries]);
        } catch (Throwable $e) {
            $this->logger->error('[fof/translate] DeepL failed to list glossaries: '.$e->getMessage());

            throw new ValidationException(['deepl' => $this->translator->trans('fof-translate.api.glossaries_failed')]);
        }
    }
}
