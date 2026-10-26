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

use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Page loads must not pay for the extension: detectedLang is a plain column, and no
 * translation table, provider or driver is touched.
 */
class QueryBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Cached</p></t>', 'provider' => 'deepl'],
        ]]);
    }

    /**
     * @return list<string>
     */
    protected function queriesDuring(callable $callback): array
    {
        $queries = [];
        $this->database()->listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    #[Test]
    public function post_list_touches_no_translation_tables(): void
    {
        $queries = $this->queriesDuring(function () {
            $response = $this->send($this->request('GET', '/api/posts', ['authenticatedAs' => 2])->withQueryParams(['filter' => ['discussion' => '1']]));
            $this->assertSame(200, $response->getStatusCode());

            $posts = collect($this->body($response)['data'])->keyBy('id');
            $this->assertSame('de', $posts['2']['attributes']['detectedLang']);
        });

        $this->assertEmpty(array_filter($queries, fn ($sql) => str_contains($sql, '_translations')));
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function discussion_list_and_show_touch_no_translation_tables(): void
    {
        $queries = $this->queriesDuring(function () {
            $response = $this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => 2]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('de', $this->body($response)['data'][0]['attributes']['detectedLang']);

            $this->assertSame(200, $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2]))->getStatusCode());
        });

        $this->assertEmpty(array_filter($queries, fn ($sql) => str_contains($sql, '_translations')));
        $this->assertCount(0, FakeDriver::$requests);
    }
}
