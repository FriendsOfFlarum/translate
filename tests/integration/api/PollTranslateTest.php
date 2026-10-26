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

use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Translate\Tests\fixtures\FakeBatchDriver;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PollTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-polls');
        $this->seedForum();

        $this->prepareDatabase([
            Poll::class => [
                ['id' => 1, 'question' => 'Welche Farbe magst du?', 'subtitle' => 'Bitte ehrlich', 'post_id' => 2, 'user_id' => 3],
                ['id' => 2, 'question' => 'Secret poll', 'post_id' => 3, 'user_id' => 3],
            ],
            PollOption::class => [
                ['id' => 1, 'poll_id' => 1, 'answer' => 'Rot'],
                ['id' => 2, 'poll_id' => 1, 'answer' => 'Blau'],
                ['id' => 3, 'poll_id' => 2, 'answer' => 'Hidden'],
            ],
        ]);
    }

    #[Test]
    public function translates_poll_and_options_in_one_document(): void
    {
        $response = $this->translate('polls', 1, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->body($response);
        $this->assertSame('poll-translations', $body['data']['type']);
        $this->assertSame(1, $body['data']['attributes']['pollId']);
        $this->assertSame('[en] Welche Farbe magst du?', $body['data']['attributes']['question']);
        $this->assertSame('[en] Bitte ehrlich', $body['data']['attributes']['subtitle']);
        $this->assertNull($body['data']['attributes']['imageAlt']);
        $this->assertSame('de', $body['data']['attributes']['sourceLanguage']);

        $this->assertCount(2, $body['data']['relationships']['options']['data']);
        $included = collect($body['included'])->keyBy(fn ($r) => $r['attributes']['optionId']);
        $this->assertSame('poll-option-translations', $included[1]['type']);
        $this->assertSame('[en] Rot', $included[1]['attributes']['answer']);
        $this->assertSame('[en] Blau', $included[2]['attributes']['answer']);

        // question + subtitle + 2 answers
        $this->assertCount(4, FakeDriver::$requests);

        $again = $this->translate('polls', 1, ['locale' => 'en']);
        $this->assertSame(200, $again->getStatusCode());
        $this->assertCount(2, $this->body($again)['included']);
        $this->assertCount(4, FakeDriver::$requests);
    }

    #[Test]
    public function only_stale_options_are_retranslated(): void
    {
        $this->translate('polls', 1, ['locale' => 'en']);
        $this->database()->table('poll_option_translations')->where('option_id', 2)->update(['update_needed' => true]);

        $this->translate('polls', 1, ['locale' => 'en']);

        $this->assertCount(5, FakeDriver::$requests);
        $this->assertSame('Blau', FakeDriver::$requests[4]->text);
    }

    #[Test]
    public function batch_driver_translates_a_five_option_poll_in_one_call(): void
    {
        $this->setting('fof-translate.driver', 'fake-batch');
        $this->prepareDatabase([PollOption::class => [
            ['id' => 4, 'poll_id' => 1, 'answer' => 'Grün'],
            ['id' => 5, 'poll_id' => 1, 'answer' => 'Gelb'],
            ['id' => 6, 'poll_id' => 1, 'answer' => 'Schwarz'],
        ]]);

        $response = $this->translate('polls', 1, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(5, $this->body($response)['included']);
        $this->assertSame('[en] Bitte ehrlich', $this->body($response)['data']['attributes']['subtitle']);

        $this->assertCount(1, FakeBatchDriver::$batches);
        $this->assertCount(7, FakeBatchDriver::$batches[0]);
        $this->assertSame('Welche Farbe magst du?', FakeBatchDriver::$batches[0][0]->context);
        $this->assertSame('fake-batch', $this->database()->table('poll_option_translations')->where('option_id', 6)->value('provider'));
    }

    #[Test]
    public function poll_on_hidden_post_404s(): void
    {
        $this->assertSame(404, $this->translate('polls', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function editing_the_question_marks_poll_translations_stale(): void
    {
        $this->translate('polls', 1, ['locale' => 'en']);

        $response = $this->send($this->request('PATCH', '/api/polls/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['attributes' => ['question' => 'Welche Farbe magst du am liebsten?']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(1, $this->database()->table('poll_translations')->where('poll_id', 1)->value('update_needed'));
        $this->assertEquals(0, $this->database()->table('poll_option_translations')->where('option_id', 1)->value('update_needed'));
    }

    #[Test]
    public function poll_language_is_not_detected_for_readers_who_cannot_translate(): void
    {
        $this->setting('fof-translate.translate-all-enabled', false);
        $this->database()->table('group_permission')->where('permission', 'fof-translate.translate')->delete();

        $response = $this->send($this->request('GET', '/api/polls/1', ['authenticatedAs' => 2]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertArrayNotHasKey('detectedLang', $this->body($response)['data']['attributes']);
    }

    #[Test]
    public function poll_detected_lang_is_computed_without_queries(): void
    {
        $response = $this->send($this->request('GET', '/api/polls/1', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('de', $this->body($response)['data']['attributes']['detectedLang']);

        $withOptions = Poll::query()->with('options')->find(1);
        $withoutOptions = Poll::query()->find(1);
        $language = $this->app()->getContainer()->make(\FoF\Translate\Detection\PollLanguage::class);

        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertSame('de', $language->of($withOptions));
        $this->assertSame('de', $language->of($withoutOptions));
        $this->assertFalse($withoutOptions->relationLoaded('options'));
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function translating_a_poll_into_its_own_detected_language_is_refused(): void
    {
        $this->assertSame(422, $this->translate('polls', 1, ['locale' => 'de'])->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function driver_reporting_the_target_language_stores_no_poll_rows(): void
    {
        FakeDriver::$detected = 'en';

        $response = $this->translate('polls', 1, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('/data/attributes/locale', $this->body($response)['errors'][0]['source']['pointer']);
        $this->assertSame(0, $this->database()->table('poll_translations')->count());
        $this->assertSame(0, $this->database()->table('poll_option_translations')->count());

        $calls = count(FakeDriver::$requests);
        $this->assertSame(422, $this->translate('polls', 1, ['locale' => 'en'])->getStatusCode());
        $this->assertCount($calls, FakeDriver::$requests);
    }
}
