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

class DiscussionTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    #[Test]
    public function translates_the_title_and_caches_it(): void
    {
        $response = $this->translate('discussions', 1, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->body($response);
        $this->assertSame('discussion-translations', $body['data']['type']);
        $this->assertSame('[en] Ein deutscher Titel', $body['data']['attributes']['title']);
        $this->assertSame('de', $body['data']['attributes']['sourceLanguage']);
        $this->assertTrue(FakeDriver::$requests[0]->isPlainText);

        $this->translate('discussions', 1, ['locale' => 'en']);
        $this->assertCount(1, FakeDriver::$requests);
        $this->assertSame(1, $this->database()->table('discussion_translations')->count());
    }

    #[Test]
    public function private_discussion_404s(): void
    {
        $this->assertSame(404, $this->translate('discussions', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function without_translate_any_language_only_the_current_locale_is_allowed(): void
    {
        $this->setting('fof-translate.translate-all-enabled', false);

        $this->assertSame(422, $this->translate('discussions', 1, ['locale' => 'fr'])->getStatusCode());
        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'en'])->getStatusCode());
    }

    #[Test]
    public function moderators_may_pick_any_language_when_translate_all_is_disabled(): void
    {
        $this->setting('fof-translate.translate-all-enabled', false);
        $this->prepareDatabase(['group_user' => [['user_id' => 2, 'group_id' => 4]]]);

        $this->assertSame(200, $this->translate('discussions', 1, ['locale' => 'fr'])->getStatusCode());
    }
}
