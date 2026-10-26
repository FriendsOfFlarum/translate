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

class DetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    protected function reply(string $content): array
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 2,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => $content],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response)['data'];
    }

    #[Test]
    public function reliable_text_is_detected_on_save_without_a_driver_call(): void
    {
        $post = $this->reply("Bonjour à tous, je voudrais savoir comment installer cette extension sur mon forum. **Merci beaucoup** pour votre aide !\n\n```\ncomposer require fof/translate\n```");

        $this->assertSame('fr', $post['attributes']['detectedLang']);
        $this->assertSame('fr', $this->database()->table('posts')->where('id', $post['id'])->value('detected_lang'));
        $this->assertCount(0, FakeDriver::$requests);
    }

    protected function setLocalePreference(int $userId, ?string $locale): void
    {
        $this->database()->table('users')->where('id', $userId)->update(['preferences' => $locale ? json_encode(['locale' => $locale]) : null]);
    }

    #[Test]
    public function short_text_takes_the_authors_forum_locale(): void
    {
        $this->setLocalePreference(2, 'de');

        $post = $this->reply('ok :)');

        $this->assertSame('de', $post['attributes']['detectedLang']);
        $this->assertSame('de', $this->database()->table('posts')->where('id', $post['id'])->value('detected_lang'));
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function short_text_without_a_locale_preference_takes_the_forum_default(): void
    {
        $this->setLocalePreference(2, null);

        $this->assertSame('en', $this->reply('Danke!')['attributes']['detectedLang']);
    }

    #[Test]
    public function a_preference_for_a_locale_the_forum_does_not_have_is_ignored(): void
    {
        $this->setLocalePreference(2, 'ja');

        $this->assertSame('en', $this->reply('+1')['attributes']['detectedLang']);
    }

    #[Test]
    public function short_text_edited_by_someone_else_takes_the_authors_locale_not_the_editors(): void
    {
        // Post 2 is by user 3; the admin editing it uses the forum default (en).
        $this->setLocalePreference(3, 'fr');

        $response = $this->send($this->request('PATCH', '/api/posts/2', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => '2', 'attributes' => ['content' => 'Merci !']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('fr', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));
    }

    #[Test]
    public function text_free_content_stays_undetermined(): void
    {
        $this->setLocalePreference(2, 'de');

        $post = $this->reply('https://example.com/screenshot.png');

        $this->assertNull($post['attributes']['detectedLang']);
        $this->assertSame('', $this->database()->table('posts')->where('id', $post['id'])->value('detected_lang'));
    }

    #[Test]
    public function short_discussion_with_a_short_first_post_takes_the_authors_locale(): void
    {
        $this->setLocalePreference(2, 'fr');

        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Salut', 'content' => 'Ça va ?']]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = $this->body($response)['data']['id'];
        $this->assertSame('fr', $this->database()->table('discussions')->where('id', $id)->value('detected_lang'));
        $this->assertSame('fr', $this->database()->table('posts')->where('discussion_id', $id)->value('detected_lang'));
    }

    #[Test]
    public function short_discussion_title_falls_back_to_the_submitted_content(): void
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => [
                'type' => 'discussions',
                'attributes' => [
                    'title' => 'Hilfe',
                    'content' => 'Ich habe versucht, die Erweiterung zu installieren, aber es funktioniert leider überhaupt nicht.',
                ],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('de', $this->body($response)['data']['attributes']['detectedLang']);
    }

    #[Test]
    public function renaming_to_a_short_title_falls_back_to_the_first_post(): void
    {
        $response = $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => 'Frage']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('de', $this->database()->table('discussions')->where('id', 1)->value('detected_lang'));
    }

    #[Test]
    public function legacy_un_and_empty_values_are_served_as_unknown(): void
    {
        $this->database()->table('posts')->where('id', 2)->update(['detected_lang' => 'un']);
        $this->database()->table('discussions')->where('id', 1)->update(['detected_lang' => '']);

        $discussion = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2]));
        $this->assertSame(200, $discussion->getStatusCode());
        $this->assertNull($this->body($discussion)['data']['attributes']['detectedLang']);

        $post = $this->send($this->request('GET', '/api/posts/2', ['authenticatedAs' => 2]));
        $this->assertSame(200, $post->getStatusCode());
        $this->assertNull($this->body($post)['data']['attributes']['detectedLang']);
    }

    #[Test]
    public function new_discussion_title_is_detected(): void
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => [
                'type' => 'discussions',
                'attributes' => [
                    'title' => 'Wie installiere ich diese Erweiterung auf meinem Forum?',
                    'content' => 'Ich habe versucht, die Erweiterung zu installieren, aber es funktioniert leider nicht.',
                ],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->body($response);
        $this->assertSame('de', $body['data']['attributes']['detectedLang']);
        $this->assertSame('de', $this->database()->table('discussions')->where('id', $body['data']['id'])->value('detected_lang'));
        $this->assertSame('de', $this->database()->table('posts')->where('discussion_id', $body['data']['id'])->value('detected_lang'));
        $this->assertCount(0, FakeDriver::$requests);
    }
}
