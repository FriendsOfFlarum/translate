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

class AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    #[Test]
    public function admin_payload_lists_drivers_without_network(): void
    {
        $response = $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());

        preg_match('#<script id="flarum-json-payload" type="application/json">(.*?)</script>#s', (string) $response->getBody(), $matches);
        $this->assertNotEmpty($matches, 'payload missing');

        $drivers = json_decode($matches[1], true)['fof-translate.drivers'] ?? null;
        $this->assertIsArray($drivers);
        $this->assertSame(['deepl', 'openai', 'claude', 'ollama', 'google', 'fake', 'fake-batch', 'fake-settings'], array_keys($drivers));
        $this->assertTrue($drivers['fake']['configured']);
        $this->assertSame('fake', $drivers['fake']['name']);
        $this->assertFalse($drivers['deepl']['configured']);
        $this->assertArrayHasKey('fields', $drivers['deepl']);
    }

    #[Test]
    public function glossaries_endpoint_is_admin_only(): void
    {
        $this->assertSame(403, $this->send($this->request('GET', '/api/fof-translate/deepl/glossaries', ['authenticatedAs' => 2]))->getStatusCode());

        $response = $this->send($this->request('GET', '/api/fof-translate/deepl/glossaries', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode((string) $response->getBody(), true));
    }
}
