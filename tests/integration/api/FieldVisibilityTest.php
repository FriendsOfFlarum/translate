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
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A translation is as visible as the original field: another extension restricting the
 * field (not the model) must restrict the translation too.
 */
class FieldVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // User 2 may load posts and discussions, but not read their content or title.
        $this->extend(
            (new Extend\ApiResource(PostResource::class))
                ->field('contentHtml', fn (Schema\Str $field) => $field->visible(fn (Post $post, Context $context) => $context->getActor()->id !== 2)),
            (new Extend\ApiResource(DiscussionResource::class))
                ->field('title', fn (Schema\Str $field) => $field->visible(fn (Discussion $discussion, Context $context) => $context->getActor()->id !== 2))
        );

        $this->seedForum();
    }

    #[Test]
    public function a_reader_who_cannot_see_a_posts_content_cannot_get_its_translation(): void
    {
        $post = $this->send($this->request('GET', '/api/posts/2', ['authenticatedAs' => 2]));
        $this->assertSame(200, $post->getStatusCode());
        $this->assertArrayNotHasKey('contentHtml', $this->body($post)['data']['attributes']);

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function a_reader_who_cannot_see_a_discussion_title_cannot_get_its_translation(): void
    {
        $discussion = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2]));
        $this->assertSame(200, $discussion->getStatusCode());
        $this->assertArrayNotHasKey('title', $this->body($discussion)['data']['attributes']);

        $response = $this->translate('discussions', 1, ['locale' => 'en']);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function readers_who_can_see_the_field_still_get_translations(): void
    {
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'], as: 1)->getStatusCode());
        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en'], as: 1)->getStatusCode());
    }
}
