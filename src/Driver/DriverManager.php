<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two paths with different budgets:
 *  - page loads (`status()`, `targetLanguages()`): a cache read; on a miss the driver is
 *    constructed only to call isConfigured(), which never touches the network;
 *  - translate requests (`targetLanguages(resolve: true)`, `current()`): may call the
 *    provider to list its real target languages, and cache them.
 *
 * Entries are keyed by a fingerprint of the driver's settings, so they never need
 * expiring: any settings change produces a new key. The fingerprint hashes no secrets.
 */
class DriverManager
{
    /** Seconds a failed target-language listing is not retried. */
    public const FAILURE_TTL = 300;

    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings,
        protected Cache $cache,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, class-string<TranslationDriver>>
     */
    public function registered(): array
    {
        return $this->container->make('fof-translate.drivers');
    }

    public function currentName(): ?string
    {
        $name = $this->settings->get('fof-translate.driver');

        return is_string($name) && isset($this->registered()[$name]) ? $name : null;
    }

    /**
     * The configured driver, or null when none is selected or it is not configured.
     * Translate path only: constructs the driver.
     */
    public function current(): ?TranslationDriver
    {
        $name = $this->currentName();

        if ($name === null) {
            return null;
        }

        $driver = $this->make($name);

        return $driver->isConfigured() ? $driver : null;
    }

    public function make(string $name): TranslationDriver
    {
        return $this->container->make($this->registered()[$name]);
    }

    /**
     * Cached state of the current driver. `targets` is null until a translate request
     * has resolved the provider's list.
     *
     * @return array{configured: bool, targets: list<string>|null}
     */
    public function status(): array
    {
        $name = $this->currentName();

        if ($name === null) {
            return ['configured' => false, 'targets' => null];
        }

        $key = $this->statusKey($name);
        $cached = $this->cache->get($key);

        if (is_array($cached) && isset($cached['configured'])) {
            return $cached;
        }

        $status = ['configured' => $this->make($name)->isConfigured(), 'targets' => null];

        $this->cache->forever($key, $status);

        return $status;
    }

    /**
     * Lower-case ISO 639-1 codes the current driver can translate into.
     *
     * Without $resolve (page loads) this never reaches the provider: it returns null when
     * the driver is configured but its list has not been resolved yet, meaning "unknown,
     * offer the forum locales". With $resolve (translate path) a missing list is fetched
     * from the provider and cached; null is still returned if that listing fails.
     *
     * @return list<string>|null [] when no driver is usable
     */
    public function targetLanguages(bool $resolve = false): ?array
    {
        $status = $this->status();

        if (! $status['configured']) {
            return [];
        }

        if ($status['targets'] !== null || ! $resolve) {
            return $status['targets'];
        }

        $name = (string) $this->currentName();
        $key = $this->statusKey($name);
        $failureKey = $key.'.failed';

        if ($this->cache->has($failureKey)) {
            return null;
        }

        $driver = $this->make($name);

        try {
            $targets = array_values(array_unique(array_map('strtolower', $driver->targetLanguages())));
        } catch (Throwable $e) {
            $this->logger->error('[fof/translate] '.$name.' failed to list target languages: '.$e->getMessage());

            $this->cache->put($failureKey, true, self::FAILURE_TTL);

            return null;
        }

        $this->cache->forever($key, ['configured' => true, 'targets' => $targets]);

        return $targets;
    }

    /**
     * Hash of the driver's non-secret settings plus whether an API key is present
     * (setting or env var). Rotating a key keeps the fingerprint; adding or removing one changes it.
     */
    public function fingerprint(string $name): string
    {
        $prefix = "fof-translate.$name.";
        $values = [];

        foreach ($this->settings->all() as $key => $value) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $values[$key] = $this->isSecret($key)
                ? (is_string($value) && trim($value) !== '')
                : $value;
        }

        $env = strtoupper($name).'_API_KEY';

        foreach (["FOF_TRANSLATE_$env", "IANM_TRANSLATE_$env"] as $variable) {
            $value = $_ENV[$variable] ?? $_SERVER[$variable] ?? getenv($variable);
            $values['env.'.$variable] = is_string($value) && trim($value) !== '';
        }

        ksort($values);

        return substr(sha1(serialize($values)), 0, 16);
    }

    protected function statusKey(string $name): string
    {
        return 'fof-translate.driver-status.'.$name.'.'.$this->fingerprint($name);
    }

    protected function isSecret(string $key): bool
    {
        return (bool) preg_match('/(api_key|token|secret|password)$/i', $key);
    }
}
