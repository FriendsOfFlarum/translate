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
use Flarum\Api\Schema;
use Flarum\Extend;
use FoF\Polls\Api\Resource\PollResource;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PollFieldVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-polls');

        // User 2 may load the poll, but not read its question.
        $this->extend(
            (new Extend\ApiResource(PollResource::class))
                ->field('question', fn (Schema\Str $field) => $field->visible(fn (Poll $poll, Context $context) => $context->getActor()->id !== 2))
        );

        $this->seedForum();
        $this->prepareDatabase([
            Poll::class => [['id' => 1, 'question' => 'Welche Farbe magst du?', 'post_id' => 2, 'user_id' => 3]],
            PollOption::class => [['id' => 1, 'poll_id' => 1, 'answer' => 'Rot']],
        ]);
    }

    #[Test]
    public function a_reader_who_cannot_see_a_poll_question_cannot_get_its_translation(): void
    {
        $poll = $this->send($this->request('GET', '/api/polls/1', ['authenticatedAs' => 2]));
        $this->assertSame(200, $poll->getStatusCode(), (string) $poll->getBody());
        $this->assertArrayNotHasKey('question', $this->body($poll)['data']['attributes']);

        $response = $this->translate('polls', 1, ['locale' => 'en']);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(0, FakeDriver::$requests);
    }
}
