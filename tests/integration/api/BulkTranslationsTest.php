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

use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Post;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PostTranslation;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /api/discussions/{id}/translations`: one request returns every cached, current
 * translation of a thread the reader may see, so a translated thread opens in one round
 * trip. It never calls a provider; the client translates the misses itself.
 */
class BulkTranslationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
        $this->prepareDatabase([
            PostTranslation::class => [
                ['id' => 1, 'post_id' => 1, 'language' => 'en', 'content' => '<t><p>Cached first post</p></t>'],
                ['id' => 2, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Stale reply</p></t>', 'update_needed' => 1],
            ],
            DiscussionTranslation::class => [
                ['id' => 1, 'discussion_id' => 1, 'language' => 'en', 'translation' => 'Cached title'],
            ],
        ]);
    }

    /**
     * @param list<string> $posts
     */
    protected function bulk(int $discussion, string $locale, array $posts, ?int $as = 2): ResponseInterface
    {
        $options = ['json' => ['data' => ['attributes' => ['locale' => $locale, 'posts' => $posts]]]];

        if ($as !== null) {
            $options['authenticatedAs'] = $as;
        }

        return $this->send($this->request('POST', "/api/discussions/$discussion/translations", $options));
    }

    /**
     * @return array<string, array<string, mixed>> type:key => attributes
     */
    protected function resources(ResponseInterface $response): array
    {
        $found = [];

        foreach ($this->body($response)['data'] as $resource) {
            $key = $resource['type'] === 'post-translations' ? $resource['attributes']['postId'] : 'title';
            $found[$resource['type'].':'.$key] = $resource['attributes'];
        }

        return $found;
    }

    #[Test]
    public function returns_the_cached_current_translations_of_a_thread_in_one_request(): void
    {
        $response = $this->bulk(1, 'en', ['1', '2']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $found = $this->resources($response);
        $this->assertSame(['discussion-translations:title', 'post-translations:1'], array_keys($found));
        $this->assertSame('Cached title', $found['discussion-translations:title']['title']);
        $this->assertStringContainsString('Cached first post', $found['post-translations:1']['contentHtml']);
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function only_posts_of_this_discussion_that_the_reader_may_see_are_returned(): void
    {
        // Post 2 is hidden; post 3 belongs to private discussion 2; post 9 to visible discussion 3.
        // All have current translations.
        // Seed before touching database(): prepareDatabase() is a no-op once the app has booted (FND-192).
        $this->prepareDatabase([
            \Flarum\Discussion\Discussion::class => [
                ['id' => 3, 'title' => 'Eine andere Diskussion', 'user_id' => 3, 'first_post_id' => 9],
            ],
            \Flarum\Post\Post::class => [
                ['id' => 9, 'discussion_id' => 3, 'number' => 1, 'user_id' => 3, 'content' => '<t><p>Anderswo</p></t>'],
            ],
            PostTranslation::class => [
                ['id' => 3, 'post_id' => 3, 'language' => 'en', 'content' => '<t><p>Secret</p></t>'],
                ['id' => 9, 'post_id' => 9, 'language' => 'en', 'content' => '<t><p>Elsewhere</p></t>'],
            ],
        ]);
        $this->database()->table('posts')->where('id', 2)->update(['hidden_at' => '2026-10-25 10:00:00']);
        $this->database()->table('post_translations')->where('id', 2)->update(['update_needed' => 0]);

        $response = $this->bulk(1, 'en', ['1', '2', '3', '9']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['discussion-translations:title', 'post-translations:1'], array_keys($this->resources($response)));
    }

    #[Test]
    public function rendering_posts_with_mentions_costs_the_same_queries_for_any_page_size(): void
    {
        $this->extension('flarum-mentions');
        $this->prepareDatabase($this->mentioningPosts(range(10, 16)));

        $page = fn (array $ids) => $this->queryCount(fn () => $this->assertSame(200, $this->bulk(1, 'en', array_map('strval', $ids))->getStatusCode()));

        $page([10]); // warms per-request caches (settings, permissions) out of the comparison

        $this->assertSame($page([10, 11]), $page(range(10, 16)));

        $html = collect($this->body($this->bulk(1, 'en', ['16']))['data'])
            ->where('type', 'post-translations')
            ->pluck('attributes.contentHtml')
            ->first();

        $this->assertStringContainsString('@member16', $html);
        $this->assertStringContainsString('data-id="1"', $html);
    }

    #[Test]
    public function a_reader_who_cannot_see_the_source_fields_gets_neither_title_nor_posts(): void
    {
        $this->extend(
            (new Extend\ApiResource(PostResource::class))
                ->field('contentHtml', fn (Schema\Str $field) => $field->visible(fn (Post $post, Context $context) => $context->getActor()->id !== 2)),
            (new Extend\ApiResource(DiscussionResource::class))
                ->field('title', fn (Schema\Str $field) => $field->visible(fn (Discussion $discussion, Context $context) => $context->getActor()->id !== 2))
        );

        $response = $this->bulk(1, 'en', ['1']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->body($response)['data']);
    }

    #[Test]
    public function a_discussion_the_reader_cannot_see_is_not_found(): void
    {
        $this->assertSame(404, $this->bulk(2, 'en', ['3'])->getStatusCode());
    }

    #[Test]
    public function permission_and_locale_are_checked(): void
    {
        $this->setting('fof-translate.translate-all-enabled', false);
        $this->database()->table('group_permission')->where('permission', 'fof-translate.translate')->delete();

        $this->assertSame(403, $this->bulk(1, 'en', ['1'])->getStatusCode());
    }

    #[Test]
    public function a_locale_outside_the_readers_targets_is_rejected(): void
    {
        $response = $this->bulk(1, 'xx', ['1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('/data/attributes/locale', $this->body($response)['errors'][0]['source']['pointer']);
    }
}
