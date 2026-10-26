<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Driver;

use Flarum\Locale\LocaleManager;
use Flarum\Settings\OverrideSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Settings\UninstalledSettingsRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

abstract class DriverTestCase extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    protected MockHandler $mock;

    protected function tearDown(): void
    {
        foreach (['FOF_TRANSLATE_CLAUDE_API_KEY', 'IANM_TRANSLATE_CLAUDE_API_KEY', 'FOF_TRANSLATE_OPENAI_API_KEY', 'FOF_TRANSLATE_DEEPL_API_KEY'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): SettingsRepositoryInterface
    {
        return new OverrideSettingsRepository(new UninstalledSettingsRepository(), $values);
    }

    /**
     * @param list<string> $locales
     */
    protected function locales(array $locales = ['en', 'de']): LocaleManager
    {
        $manager = $this->createStub(LocaleManager::class);
        $manager->method('getLocales')->willReturn(array_combine($locales, $locales));

        return $manager;
    }

    /**
     * @param list<Response> $responses
     */
    protected function mockHttp(array $responses): Client
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    protected function lastRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No HTTP request was sent.');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastRequestBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
