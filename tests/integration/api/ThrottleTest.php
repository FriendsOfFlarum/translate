<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration\api;

use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
        $this->setting('fof-translate.throttle_per_minute', 2);
    }

    #[Test]
    public function returns_429_after_the_limit(): void
    {
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en'])->getStatusCode());
        $this->assertSame(429, $this->translate('posts', 2, ['locale' => 'fr'])->getStatusCode());

        // Another actor has their own budget.
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'fr'], null)->getStatusCode());
    }

    #[Test]
    public function the_bulk_thread_lookup_shares_the_budget(): void
    {
        $bulk = fn () => $this->send($this->request('POST', '/api/discussions/1/translations', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['attributes' => ['locale' => 'en', 'posts' => ['1', '2']]]],
        ]))->getStatusCode();

        $this->assertSame(200, $bulk());
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertSame(429, $bulk());
    }

    #[Test]
    public function other_routes_are_not_throttled(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(200, $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2]))->getStatusCode());
        }
    }
}
