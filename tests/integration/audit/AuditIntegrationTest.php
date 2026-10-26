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
use FoF\Translate\Model\PostTranslation;
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

    /**
     * MySQL's JSON type stores an object's keys in its own order, not the order they were
     * written in, so payloads are compared by key.
     *
     * @param list<array{action: string, payload: array<string, mixed>|null}> $expected
     */
    protected function assertLogged(array $expected): void
    {
        $byKey = fn (array $entries) => array_map(function (array $entry) {
            if (is_array($entry['payload'])) {
                ksort($entry['payload']);
            }

            return $entry;
        }, $entries);

        $this->assertSame($byKey($expected), $byKey($this->logged()));
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
        $this->assertLogged([]);

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true], 1)->getStatusCode());

        $this->assertLogged([
            ['action' => 'fof_translate.post_translation_refreshed', 'payload' => ['post_id' => 2, 'discussion_id' => 1, 'locale' => 'en']],
        ]);
    }

    #[Test]
    public function a_refreshed_title_is_logged_against_its_discussion(): void
    {
        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en', 'refresh' => true], 1)->getStatusCode());

        $this->assertLogged([
            ['action' => 'fof_translate.discussion_translation_refreshed', 'payload' => ['discussion_id' => 1, 'locale' => 'en']],
        ]);
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

        $this->assertLogged([
            ['action' => 'fof_translate.poll_translation_refreshed', 'payload' => ['poll_id' => 1, 'post_id' => 2, 'discussion_id' => 1, 'locale' => 'en']],
        ]);
    }

    #[Test]
    public function a_refresh_asked_for_without_permission_is_not_logged(): void
    {
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true])->getStatusCode());

        $this->assertLogged([]);
    }

    #[Test]
    public function a_driver_test_is_logged_with_its_outcome(): void
    {
        $response = $this->send($this->request('POST', '/api/fof-translate/drivers/fake/test', ['authenticatedAs' => 1, 'json' => []]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertLogged([
            ['action' => 'fof_translate.driver_tested', 'payload' => ['driver' => 'fake', 'ok' => true]],
        ]);
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
        $this->assertLogged([
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'posts', 'force' => true, 'rows' => 3, 'source' => 'admin']],
        ]);
    }

    #[Test]
    public function a_detection_run_from_the_cli_is_logged_once_per_type(): void
    {
        $this->runCommand(['command' => 'fof:translate:detect', '--force' => true]);

        $this->assertLogged([
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'posts', 'force' => true, 'rows' => 3, 'source' => 'cli']],
            ['action' => 'fof_translate.language_detection_run', 'payload' => ['type' => 'discussions', 'force' => true, 'rows' => 2, 'source' => 'cli']],
        ]);
    }

    #[Test]
    public function pruning_is_logged_with_what_it_deleted_and_a_dry_run_is_not(): void
    {
        $this->prepareDatabase([PostTranslation::class => [
            ['id' => 1, 'post_id' => 2, 'language' => 'de'],
            ['id' => 2, 'post_id' => 2, 'language' => 'en'],
        ]]);

        $this->runCommand(['command' => 'fof:translate:prune', '--dry-run' => true]);
        $this->assertLogged([]);

        $this->runCommand(['command' => 'fof:translate:prune']);

        $this->assertLogged([
            ['action' => 'fof_translate.translations_pruned', 'payload' => ['same_language' => 1, 'orphaned_polls' => 0, 'orphaned_poll_options' => 0]],
        ]);
    }

    #[Test]
    public function an_xml_repair_is_logged_with_its_count_and_a_dry_run_is_not(): void
    {
        $this->prepareDatabase([PostTranslation::class => [
            ['id' => 1, 'post_id' => 2, 'language' => 'fr', 'content' => '<t><p>Tom & Jerry</p></t>'],
        ]]);

        $this->runCommand(['command' => 'fof:translate:repair-xml']);
        $this->assertLogged([]);

        $this->runCommand(['command' => 'fof:translate:repair-xml', '--fix' => true]);

        $this->assertLogged([
            ['action' => 'fof_translate.translations_repaired', 'payload' => ['rows' => 1]],
        ]);
    }
}
