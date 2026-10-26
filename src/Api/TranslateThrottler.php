<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Caps provider spend: N translate calls per actor (per IP for guests) per minute,
 * setting `fof-translate.throttle_per_minute` (0 disables). Cached rows count too;
 * the throttler runs before the endpoint and cannot know whether a call will hit.
 */
class TranslateThrottler
{
    public const WINDOW = 60;

    public function __construct(
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (! in_array($request->getAttribute('routeName'), [...TranslateEndpoint::ROUTES, BulkTranslationsEndpoint::ROUTE], true)) {
            return null;
        }

        $limit = (int) $this->settings->get('fof-translate.throttle_per_minute', 30);

        if ($limit <= 0) {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        // Never return false here: that would override every other throttler.
        if ($actor->isAdmin()) {
            return null;
        }

        $who = $actor->isGuest()
            ? 'ip.'.sha1((string) $request->getAttribute('ipAddress'))
            : 'user.'.$actor->id;

        $key = 'fof-translate.throttle.'.$who.'.'.intdiv(time(), self::WINDOW);

        $this->cache->add($key, 0, self::WINDOW * 2);
        $count = (int) $this->cache->increment($key);

        return $count > $limit ? true : null;
    }
}
