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

use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PostTranslation;
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
        $this->prepareDatabase([
            PostTranslation::class => [
                ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Cached</p></t>'],
            ],
            DiscussionTranslation::class => [
                ['id' => 1, 'discussion_id' => 1, 'language' => 'en', 'translation' => 'Cached title'],
            ],
        ]);
    }

    /**
     * How many times `$queries` read `$table` (with any prefix), subqueries included.
     *
     * @param list<string> $queries
     */
    protected function reads(string $table, array $queries): int
    {
        return array_sum(array_map(fn (string $sql) => preg_match_all('/\bfrom [`"]?\w*?'.$table.'[`"]?(?=[\s)]|$)/i', $sql), $queries));
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
    public function a_cached_post_translation_reads_its_post_once(): void
    {
        $queries = $this->queriesDuring(fn () => $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode()));

        $this->assertSame(1, $this->reads('posts', $queries));
    }

    #[Test]
    public function a_cached_title_translation_reads_its_discussion_once(): void
    {
        $queries = $this->queriesDuring(fn () => $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en'])->getStatusCode()));

        $this->assertSame(1, $this->reads('discussions', $queries));
    }

    #[Test]
    public function a_bulk_lookup_reads_its_discussion_once(): void
    {
        $queries = $this->queriesDuring(fn () => $this->assertSame(200, $this->send($this->request('POST', '/api/discussions/1/translations', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['attributes' => ['locale' => 'en', 'posts' => ['1', '2']]]],
        ]))->getStatusCode()));

        $this->assertSame(1, $this->reads('discussions', $queries));
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
