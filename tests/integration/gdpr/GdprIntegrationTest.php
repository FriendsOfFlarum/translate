<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration\gdpr;

use Carbon\Carbon;
use Flarum\Gdpr\DataProcessor;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Post\Post;
use Flarum\User\User;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Translate\Gdpr\TranslationData;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PollOptionTranslation;
use FoF\Translate\Model\PollTranslation;
use FoF\Translate\Model\PostTranslation;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GdprIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-gdpr');
        $this->seedForum();

        $this->prepareDatabase([
            Post::class => [
                // A post by someone else in the same discussion, with its own translation.
                ['id' => 5, 'discussion_id' => 1, 'number' => 4, 'user_id' => 2, 'content' => '<t><p>Ich bin jemand anderes.</p></t>', 'detected_lang' => 'de'],
            ],
            PostTranslation::class => [
                ['id' => 1, 'post_id' => 1, 'language' => 'en', 'content' => '<t><p>This is the first post in this discussion.</p></t>'],
                ['id' => 2, 'post_id' => 2, 'language' => 'fr', 'content' => '<t><p>Bonjour à tous</p></t>'],
                ['id' => 5, 'post_id' => 5, 'language' => 'en', 'content' => '<t><p>I am someone else.</p></t>'],
            ],
            DiscussionTranslation::class => [
                ['id' => 1, 'discussion_id' => 1, 'language' => 'en', 'translation' => 'A German title'],
            ],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>> filename => decoded JSON
     */
    protected function export(int $userId): array
    {
        $container = $this->app()->getContainer();
        $type = $container->make(TranslationData::class, ['user' => User::findOrFail($userId), 'erasureRequest' => null]);

        $files = [];

        foreach ($type->export() ?? [] as $file) {
            foreach ($file as $name => $json) {
                $files[$name] = json_decode($json, true);
            }
        }

        return $files;
    }

    #[Test]
    public function the_data_type_is_registered_when_gdpr_is_enabled(): void
    {
        $this->assertArrayHasKey(TranslationData::class, $this->app()->getContainer()->make(DataProcessor::class)->types());
    }

    #[Test]
    public function export_contains_the_users_posts_and_discussion_titles_translations_only(): void
    {
        $files = $this->export(3);

        $names = array_keys($files);
        sort($names);

        $this->assertSame(['translations/discussion-1.json', 'translations/post-1.json', 'translations/post-2.json'], $names);

        $this->assertSame('de', $files['translations/post-1.json']['detected_language']);
        $this->assertSame('en', $files['translations/post-1.json']['translations'][0]['language']);
        $this->assertSame('This is the first post in this discussion.', $files['translations/post-1.json']['translations'][0]['content']);
        $this->assertSame('deepl', $files['translations/post-1.json']['translations'][0]['driver']);
        $this->assertSame('A German title', $files['translations/discussion-1.json']['translations'][0]['title']);
    }

    protected function erase(string $mode): void
    {
        if ($mode === ErasureRequest::MODE_DELETION) {
            $this->setting('flarum-gdpr.allow-deletion', true);
        }

        $this->prepareDatabase(['gdpr_erasure' => [
            ['id' => 1, 'user_id' => 3, 'verification_token' => 'abc', 'status' => 'user_confirmed', 'reason' => 'Forget me', 'created_at' => Carbon::now(), 'user_confirmed_at' => Carbon::now()],
        ]]);

        $response = $this->send($this->request('PATCH', '/api/user-erasure-requests/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'user-erasure-requests', 'id' => '1', 'attributes' => ['processorComment' => 'Done', 'processedMode' => $mode]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function deletion_removes_the_users_translations_and_keeps_everyone_elses(): void
    {
        $this->erase(ErasureRequest::MODE_DELETION);

        $remaining = $this->database()->table('post_translations')->orderBy('post_id')->pluck('post_id')->all();

        $this->assertSame([5], array_map('intval', $remaining));
    }

    #[Test]
    public function deletion_clears_translations_of_polls_that_no_longer_exist(): void
    {
        // Poll 1 is gone (on MySQL it goes with its post via fof/polls' post_id foreign key;
        // this suite's SQLite connection runs with foreign keys off, so it is simply absent).
        // Its translations are left behind wherever our tables have no foreign key to polls.
        $this->extension('fof-polls');
        $this->prepareDatabase([
            Poll::class => [['id' => 2, 'question' => 'Bleibt', 'post_id' => 5, 'user_id' => 2]],
            PollOption::class => [['id' => 2, 'poll_id' => 2, 'answer' => 'Ja']],
            PollTranslation::class => [
                ['id' => 1, 'poll_id' => 1, 'language' => 'en', 'question' => 'Which colour?'],
                ['id' => 2, 'poll_id' => 2, 'language' => 'en', 'question' => 'Stays'],
            ],
            PollOptionTranslation::class => [
                ['id' => 1, 'option_id' => 1, 'language' => 'en', 'answer' => 'Red'],
                ['id' => 2, 'option_id' => 2, 'language' => 'en', 'answer' => 'Yes'],
            ],
        ]);

        $this->erase(ErasureRequest::MODE_DELETION);

        $this->assertSame([2], array_map('intval', $this->database()->table('poll_translations')->pluck('poll_id')->all()));
        $this->assertSame([2], array_map('intval', $this->database()->table('poll_option_translations')->pluck('option_id')->all()));
    }

    #[Test]
    public function anonymization_keeps_translations_of_the_kept_content(): void
    {
        $this->erase(ErasureRequest::MODE_ANONYMIZATION);

        $this->assertSame(3, $this->database()->table('post_translations')->count());
        $this->assertSame(1, $this->database()->table('discussion_translations')->count());
    }
}
