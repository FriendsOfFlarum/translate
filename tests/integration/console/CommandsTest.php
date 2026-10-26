<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration\console;

use Flarum\Foundation\Application;
use FoF\Translate\Model\PostTranslation;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    protected function runCommand(array $input): string
    {
        $console = new ConsoleApplication('Flarum', Application::VERSION);
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);

        foreach ($this->app()->getConsoleCommands() as $command) {
            $console->add($command);
        }

        $output = new BufferedOutput();
        $console->run(new ArrayInput($input), $output);

        return trim($output->fetch());
    }

    #[Test]
    public function detect_backfills_unknown_languages_locally(): void
    {
        $this->prepareDatabase([
            \Flarum\Post\Post::class => [
                ['id' => 5, 'discussion_id' => 1, 'number' => 4, 'user_id' => 3, 'content' => '<t><p>This is a fairly long English sentence about installing extensions.</p></t>', 'detected_lang' => null],
                ['id' => 6, 'discussion_id' => 1, 'number' => 5, 'user_id' => 3, 'content' => '<t><p>ok</p></t>', 'detected_lang' => null],
                // No text at all, only a link: nothing to attribute to any language.
                ['id' => 7, 'discussion_id' => 1, 'number' => 6, 'user_id' => 3, 'content' => '<r><URL url="https://example.com/a.png">https://example.com/a.png</URL></r>', 'detected_lang' => null],
            ],
        ]);
        $this->database()->table('users')->where('id', 3)->update(['preferences' => json_encode(['locale' => 'de'])]);
        $this->database()->table('discussions')->where('id', 2)->update(['title' => 'Una discusión privada sobre la instalación de extensiones', 'detected_lang' => null]);

        // Default: posts and discussions.
        $output = $this->runCommand(['command' => 'fof:translate:detect', '--chunk' => 1]);

        $this->assertStringContainsString('Posts: 4 checked, 3 detected, 1 undetermined', $output);
        $this->assertStringContainsString('Discussions: 1 checked, 1 detected, 0 undetermined', $output);

        $this->assertSame('en', $this->database()->table('posts')->where('id', 5)->value('detected_lang'));
        // Too short for the detector: the author's forum locale.
        $this->assertSame('de', $this->database()->table('posts')->where('id', 6)->value('detected_lang'));
        $this->assertSame('de', $this->database()->table('posts')->where('id', 3)->value('detected_lang'));
        // No text at all: checked but undetermined, the '' sentinel, not NULL.
        $this->assertSame('', $this->database()->table('posts')->where('id', 7)->value('detected_lang'));
        $this->assertSame('es', $this->database()->table('discussions')->where('id', 2)->value('detected_lang'));
        // Existing values are kept without --force.
        $this->assertSame('de', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));
        $this->assertCount(0, FakeDriver::$requests);

        // A second run finds nothing left: '' rows are not re-processed.
        $again = $this->runCommand(['command' => 'fof:translate:detect']);
        $this->assertStringContainsString('Posts: 0 checked', $again);
        $this->assertStringContainsString('Discussions: 0 checked', $again);
    }

    #[Test]
    public function detect_can_be_limited_to_one_type_and_forced(): void
    {
        $this->database()->table('posts')->where('id', 2)->update(['detected_lang' => 'fr']);

        $output = $this->runCommand(['command' => 'fof:translate:detect', '--posts' => true, '--force' => true]);

        $this->assertStringContainsString('Posts:', $output);
        $this->assertStringNotContainsString('Discussions:', $output);
        $this->assertSame('de', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));
        $this->assertSame('de', $this->database()->table('discussions')->where('id', 1)->value('detected_lang'));
    }

    #[Test]
    public function prune_removes_same_language_rows(): void
    {
        $this->prepareDatabase([PostTranslation::class => [
            ['id' => 1, 'post_id' => 2, 'language' => 'de'],
            ['id' => 2, 'post_id' => 2, 'language' => 'en'],
            // Same language, but written by a human: kept.
            ['id' => 3, 'post_id' => 1, 'language' => 'de', 'edited_by' => 1],
        ]]);

        $this->runCommand(['command' => 'fof:translate:prune', '--dry-run' => true]);
        $this->assertSame(3, $this->database()->table('post_translations')->count());

        $this->runCommand(['command' => 'fof:translate:prune']);
        $this->assertSame([2, 3], $this->database()->table('post_translations')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function repair_xml_marks_corrupted_rows_but_not_edited_ones(): void
    {
        $this->prepareDatabase([PostTranslation::class => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Fine &amp; dandy</p></t>'],
            ['id' => 2, 'post_id' => 2, 'language' => 'fr', 'content' => '<t><p>Tom & Jerry</p></t>'],
            ['id' => 3, 'post_id' => 1, 'language' => 'en', 'content' => '<t><p>a < b</p></t>', 'edited_by' => 1],
        ]]);

        $this->runCommand(['command' => 'fof:translate:repair-xml']);
        $this->assertSame(0, $this->database()->table('post_translations')->where('update_needed', 1)->count());

        $this->runCommand(['command' => 'fof:translate:repair-xml', '--fix' => true]);
        $this->assertSame([2], $this->database()->table('post_translations')->where('update_needed', 1)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function repair_xml_renders_posts_with_mentions_at_the_same_query_cost_for_any_row_count(): void
    {
        $this->extension('flarum-mentions');
        $this->prepareDatabase($this->mentioningPosts(range(10, 16)));

        $repair = fn (array $ids) => $this->queryCount(fn () => $this->runCommand(['command' => 'fof:translate:repair-xml', '--ids' => implode(',', $ids)]));

        $repair([10]); // boots the command and warms caches out of the comparison

        $this->assertSame($repair([10, 11]), $repair(range(10, 16)));
    }
}
