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

class StalenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();

        $this->prepareDatabase([
            'post_translations' => [
                ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Auto</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
                ['id' => 2, 'post_id' => 2, 'language' => 'fr', 'content' => '<t><p>Humain</p></t>', 'provider' => 'manual', 'update_needed' => 0, 'edited_by' => 1],
                ['id' => 3, 'post_id' => 1, 'language' => 'en', 'content' => '<t><p>Other post</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
            ],
            'discussion_translations' => [
                ['id' => 1, 'discussion_id' => 1, 'language' => 'en', 'translation' => 'Auto', 'provider' => 'deepl', 'update_needed' => 0],
                ['id' => 2, 'discussion_id' => 1, 'language' => 'fr', 'translation' => 'Humain', 'provider' => 'manual', 'update_needed' => 0, 'edited_by' => 1],
            ],
        ]);
    }

    #[Test]
    public function revising_a_post_marks_only_its_non_edited_rows_stale_in_one_query(): void
    {
        $updates = [];
        $this->database()->listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'post_translations')) {
                $updates[] = $query->sql;
            }
        });

        $response = $this->send($this->request('PATCH', '/api/posts/2', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => '2', 'attributes' => ['content' => 'Hallo zusammen, wie geht es euch denn heute so?']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(1, $updates);

        $rows = $this->database()->table('post_translations')->pluck('update_needed', 'id');
        $this->assertEquals(1, $rows[1]);
        $this->assertEquals(0, $rows[2]);
        $this->assertEquals(0, $rows[3]);
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function renaming_a_discussion_marks_only_non_edited_rows_stale(): void
    {
        $response = $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => 'Ein neuer deutscher Titel']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $rows = $this->database()->table('discussion_translations')->pluck('update_needed', 'id');
        $this->assertEquals(1, $rows[1]);
        $this->assertEquals(0, $rows[2]);
    }
}
