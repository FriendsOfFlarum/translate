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

use FoF\Translate\Detection\DetectionRunner;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DetectionAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();

        $this->prepareDatabase([
            \Flarum\Post\Post::class => [
                ['id' => 5, 'discussion_id' => 1, 'number' => 4, 'user_id' => 3, 'content' => '<t><p>This is a fairly long English sentence about installing extensions.</p></t>', 'detected_lang' => null],
                ['id' => 6, 'discussion_id' => 1, 'number' => 5, 'user_id' => 3, 'content' => '<t><p>ok</p></t>', 'detected_lang' => ''],
                ['id' => 7, 'discussion_id' => 1, 'number' => 6, 'user_id' => 3, 'content' => '<t><p>Questo è un messaggio scritto in italiano per la prova.</p></t>', 'detected_lang' => null],
            ],
            \Flarum\Discussion\Discussion::class => [
                // Short title: falls back to the first post's text.
                ['id' => 3, 'title' => 'Hilfe', 'user_id' => 3, 'first_post_id' => 8, 'detected_lang' => null],
            ],
        ]);
        $this->prepareDatabase([\Flarum\Post\Post::class => [
            ['id' => 8, 'discussion_id' => 3, 'number' => 1, 'user_id' => 3, 'content' => '<t><p>Ich habe versucht, die Erweiterung zu installieren, aber es funktioniert leider überhaupt nicht.</p></t>', 'detected_lang' => 'de'],
        ]]);
    }

    protected function runner(): DetectionRunner
    {
        return $this->app()->getContainer()->make(DetectionRunner::class);
    }

    #[Test]
    public function counts_report_totals_and_never_checked_rows(): void
    {
        $response = $this->send($this->request('GET', '/api/fof-translate/detection', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        // Comment posts 1,2,3,5,6,7,8 (4 is an event post); pending = NULL only (3, 5, 7).
        $this->assertSame([
            'posts' => ['total' => 7, 'pending' => 3],
            'discussions' => ['total' => 3, 'pending' => 2],
        ], $this->body($response)['data']);
    }

    #[Test]
    public function batch_endpoint_processes_and_returns_a_cursor(): void
    {
        $response = $this->send($this->request('POST', '/api/fof-translate/detection', [
            'authenticatedAs' => 1,
            'json' => ['type' => 'posts', 'force' => false, 'after' => null],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        // Post 3 ("Secret") is too short for the detector: it takes its author's locale (no preference: the forum default).
        $this->assertSame(['processed' => 3, 'detected' => 3, 'after' => 7, 'done' => true], $this->body($response)['data']);
        $this->assertSame('en', $this->database()->table('posts')->where('id', 3)->value('detected_lang'));

        $this->assertSame('en', $this->database()->table('posts')->where('id', 5)->value('detected_lang'));
        $this->assertSame('it', $this->database()->table('posts')->where('id', 7)->value('detected_lang'));
        $this->assertCount(0, FakeDriver::$requests);

        $discussions = $this->send($this->request('POST', '/api/fof-translate/detection', [
            'authenticatedAs' => 1,
            'json' => ['type' => 'discussions'],
        ]));

        $data = $this->body($discussions)['data'];
        $this->assertSame([2, 3, true], [$data['processed'], $data['after'], $data['done']]);
        $this->assertSame('de', $this->database()->table('discussions')->where('id', 3)->value('detected_lang'));
    }

    #[Test]
    public function endpoints_are_admin_only_and_validate_the_type(): void
    {
        $this->assertSame(403, $this->send($this->request('GET', '/api/fof-translate/detection', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/fof-translate/detection', [
            'authenticatedAs' => 2,
            'json' => ['type' => 'posts'],
        ]))->getStatusCode());

        $invalid = $this->send($this->request('POST', '/api/fof-translate/detection', [
            'authenticatedAs' => 1,
            'json' => ['type' => 'users'],
        ]));
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertSame('/data/attributes/type', $this->body($invalid)['errors'][0]['source']['pointer']);
    }

    #[Test]
    public function undetermined_rows_are_only_reprocessed_with_force(): void
    {
        $this->database()->table('posts')->where('id', 6)->update(['content' => '<t><p>Now this post has enough English words to be detected properly.</p></t>']);

        $this->runner()->batch('posts');
        $this->assertSame('', $this->database()->table('posts')->where('id', 6)->value('detected_lang'));

        $this->runner()->batch('posts', force: true);
        $this->assertSame('en', $this->database()->table('posts')->where('id', 6)->value('detected_lang'));
    }

    #[Test]
    public function batches_follow_the_cursor_and_stop_at_the_limit(): void
    {
        $first = $this->runner()->batch('posts', limit: 2);
        $this->assertSame(['processed' => 2, 'detected' => 2, 'after' => 5, 'done' => false], $first);

        $second = $this->runner()->batch('posts', after: $first['after'], limit: 2);
        $this->assertSame(['processed' => 1, 'detected' => 1, 'after' => 7, 'done' => true], $second);

        $this->assertSame(0, $this->runner()->batch('posts', after: $second['after'])['processed']);
    }

    #[Test]
    public function batches_stop_when_the_time_budget_is_spent(): void
    {
        $result = $this->runner()->batch('posts', timeBudgetSeconds: 0.0);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(3, $result['after']);
        $this->assertFalse($result['done']);
    }

    #[Test]
    public function a_batch_writes_with_one_update(): void
    {
        $updates = 0;
        $this->database()->listen(function ($query) use (&$updates) {
            $updates += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
        });

        $this->runner()->batch('discussions');

        $this->assertSame(1, $updates);
    }
}
