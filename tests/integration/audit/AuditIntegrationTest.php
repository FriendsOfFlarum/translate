<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration\audit;

use Flarum\Foundation\Application;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Admin and moderator actions with a cost or a data effect are written to flarum/audit's
 * log. Ordinary readers' translate clicks are not: high volume, no accountability value.
 */
class AuditIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-audit');
        $this->seedForum();
    }

    /**
     * @return list<array{action: string, payload: array<string, mixed>|null}>
     */
    protected function logged(): array
    {
        return $this->database()->table('audit_log')
            ->where('action', 'like', 'fof_translate.%')
            ->orderBy('id')
            ->get(['action', 'payload'])
            ->map(fn ($row) => ['action' => $row->action, 'payload' => json_decode((string) $row->payload, true)])
            ->all();
    }

    protected function runCommand(array $input): void
    {
        $console = new ConsoleApplication('Flarum', Application::VERSION);
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);

        foreach ($this->app()->getConsoleCommands() as $command) {
            $console->add($command);
        }

        $console->run(new ArrayInput($input), new BufferedOutput());
    }

    #[Test]
    public function a_forced_refresh_is_logged_and_an_ordinary_translation_is_not(): void
    {
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertSame([], $this->logged());

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true], 1)->getStatusCode());

        $this->assertSame([
            ['action' => 'fof_translate.post_translation_refreshed', 'payload' => ['post_id' => 2, 'discussion_id' => 1, 'locale' => 'en']],
        ], $this->logged());
    }

    #[Test]
    public function a_refreshed_title_is_logged_against_its_discussion(): void
    {
        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en', 'refresh' => true], 1)->getStatusCode());

        $this->assertSame([
            ['action' => 'fof_translate.discussion_translation_refreshed', 'payload' => ['discussion_id' => 1, 'locale' => 'en']],
        ], $this->logged());
    }

    #[Test]
    public function a_refreshed_poll_is_logged_against_its_post_and_discussion(): void
    {
        $this->extension('fof-polls');
        $this->prepareDatabase([
            Poll::class => [['id' => 1, 'question' => 'Welche Farbe magst du?', 'post_id' => 2, 'user_id' => 3]],
            PollOption::class => [['id' => 1, 'poll_id' => 1, 'answer' => 'Rot']],
        ]);

        $this->assertSame(200, $this->translate('polls', 1, ['locale' => 'en', 'refresh' => true], 1)->getStatusCode());

        $this->assertSame([
            ['action' => 'fof_translate.poll_translation_refreshed', 'payload' => ['poll_id' => 1, 'post_id' => 2, 'discussion_id' => 1, 'locale' => 'en']],
        ], $this->logged());
    }

    #[Test]
    public function a_refresh_asked_for_without_permission_is_not_logged(): void
    {
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true])->getStatusCode());

        $this->assertSame([], $this->logged());
    }

    #[Test]
    public function a_driver_test_is_logged_with_its_outcome(): void
    {
        $response = $this->send($this->request('POST', '/api/fof-translate/drivers/fake/test', ['authenticatedAs' => 1, 'json' => []]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([
            ['action' => 'fof_translate.driver_tested', 'payload' => ['driver' => 'fake', 'ok' => true]],
        ], $this->logged());
    }

    #[Test]
    public function a_detection_run_from_the_admin_panel_is_logged_once_not_per_batch(): void
    {
        $batch = fn (?int $after) => $this->send($this->request('POST', '/api/fof-translate/detection', [
            'authenticatedAs' => 1,
            'json' => ['type' => 'posts', 'force' => true, 'after' => $after],
        ]));

        $this->assertSame(200, $batch(null)->getStatusCode());
        $this->assertSame(200, $batch(1)->getStatusCode());

        // Comment posts 1, 2 and 3: a forced run covers every one of them.
        $this->assertSame([
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'posts', 'force' => true, 'rows' => 3, 'source' => 'admin']],
        ], $this->logged());
    }

    #[Test]
    public function a_detection_run_from_the_cli_is_logged_once_per_type(): void
    {
        $this->runCommand(['command' => 'fof:translate:detect', '--force' => true]);

        $this->assertSame([
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'posts', 'force' => true, 'rows' => 3, 'source' => 'cli']],
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'discussions', 'force' => true, 'rows' => 2, 'source' => 'cli']],
        ], $this->logged());
    }

    #[Test]
    public function pruning_is_logged_with_what_it_deleted_and_a_dry_run_is_not(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'de', 'content' => '<t><p>x</p></t>', 'provider' => 'deepl'],
            ['id' => 2, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>x</p></t>', 'provider' => 'deepl'],
        ]]);

        $this->runCommand(['command' => 'fof:translate:prune', '--dry-run' => true]);
        $this->assertSame([], $this->logged());

        $this->runCommand(['command' => 'fof:translate:prune']);

        $this->assertSame([
            ['action' => 'fof_translate.translations_pruned', 'payload' => ['same_language' => 1, 'orphaned_polls' => 0, 'orphaned_poll_options' => 0]],
        ], $this->logged());
    }

    #[Test]
    public function an_xml_repair_is_logged_with_its_count_and_a_dry_run_is_not(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'fr', 'content' => '<t><p>Tom & Jerry</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
        ]]);

        $this->runCommand(['command' => 'fof:translate:repair-xml']);
        $this->assertSame([], $this->logged());

        $this->runCommand(['command' => 'fof:translate:repair-xml', '--fix' => true]);

        $this->assertSame([
            ['action' => 'fof_translate.translations_repaired', 'payload' => ['rows' => 1]],
        ], $this->logged());
    }
}
