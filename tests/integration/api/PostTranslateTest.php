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

use Carbon\Carbon;
use Flarum\Post\Post;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PostTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    #[Test]
    public function cache_miss_calls_the_driver_once_and_stores_the_row(): void
    {
        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->body($response);
        $this->assertSame('post-translations', $body['data']['type']);
        $this->assertSame('en', $body['data']['attributes']['language']);
        $this->assertSame('fake', $body['data']['attributes']['provider']);
        $this->assertSame('de', $body['data']['attributes']['sourceLanguage']);
        $this->assertFalse($body['data']['attributes']['isStale']);
        $this->assertFalse($body['data']['attributes']['isEdited']);
        $this->assertStringContainsString('[en] Hallo zusammen', $body['data']['attributes']['contentHtml']);
        $this->assertArrayNotHasKey('content', $body['data']['attributes']);

        $this->assertCount(1, FakeDriver::$requests);
        $request = FakeDriver::$requests[0];
        $this->assertSame('en', $request->targetLanguage);
        $this->assertSame('de', $request->sourceLanguage);
        $this->assertStringContainsString('Ein deutscher Titel', (string) $request->context);
        $this->assertStringContainsString('Das ist der erste Beitrag', (string) $request->context);
        $this->assertContains('Flarum', $request->glossary);

        $row = $this->database()->table('post_translations')->where(['post_id' => 2, 'language' => 'en'])->first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('<', $row->content);
        $this->assertStringContainsString('[en] Hallo zusammen', $row->content);
    }

    #[Test]
    public function cache_hit_makes_no_driver_call(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Cached</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
        ]]);

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Cached', $this->body($response)['data']['attributes']['contentHtml']);
        $this->assertSame('deepl', $this->body($response)['data']['attributes']['provider']);
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function stale_row_is_retranslated(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Old</p></t>', 'provider' => 'deepl', 'update_needed' => 1],
        ]]);

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(1, FakeDriver::$requests);
        $this->assertSame('1', $this->body($response)['data']['id']);

        $row = $this->database()->table('post_translations')->where('id', 1)->first();
        $this->assertSame('fake', $row->provider);
        $this->assertEquals(0, $row->update_needed);
        $this->assertSame(1, $this->database()->table('post_translations')->count());
    }

    #[Test]
    public function refresh_without_permission_is_ignored(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Cached</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
        ]]);

        $response = $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function refresh_with_permission_retranslates(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Cached</p></t>', 'provider' => 'deepl', 'update_needed' => 0],
        ]]);

        $response = $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, FakeDriver::$requests);
    }

    #[Test]
    public function edited_row_is_never_retranslated_even_on_refresh(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Human</p></t>', 'provider' => 'manual', 'update_needed' => 1, 'edited_by' => 1],
        ]]);

        $response = $this->translate('posts', 2, ['locale' => 'en', 'refresh' => true], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
        $this->assertTrue($this->body($response)['data']['attributes']['isEdited']);
        $this->assertStringContainsString('Human', $this->body($response)['data']['attributes']['contentHtml']);
    }

    #[Test]
    public function hidden_post_404s(): void
    {
        $response = $this->translate('posts', 3, ['locale' => 'en']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function locale_outside_target_list_is_422(): void
    {
        $response = $this->translate('posts', 2, ['locale' => 'xx']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function locale_not_offered_by_driver_is_422(): void
    {
        FakeDriver::$targets = ['en', 'de'];

        $response = $this->translate('posts', 2, ['locale' => 'fr']);

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function missing_locale_is_422(): void
    {
        $this->assertSame(422, $this->translate('posts', 2, [])->getStatusCode());
    }

    #[Test]
    public function same_language_is_422_without_a_driver_call(): void
    {
        $response = $this->translate('posts', 2, ['locale' => 'de']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('/data/attributes/locale', $this->body($response)['errors'][0]['source']['pointer']);
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function guest_without_permission_gets_403(): void
    {
        $this->database()->table('group_permission')->where('permission', 'fof-translate.translate')->delete();

        $response = $this->translate('posts', 2, ['locale' => 'en'], null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function guest_with_default_permission_can_translate(): void
    {
        $response = $this->translate('posts', 2, ['locale' => 'en'], null);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function non_comment_post_is_not_translatable(): void
    {
        $response = $this->translate('posts', 4, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function driver_failure_is_a_generic_422_and_caches_nothing(): void
    {
        FakeDriver::$fail = true;

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringNotContainsString('exploded', (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('post_translations')->count());
    }

    #[Test]
    public function learn_on_translate_updates_detected_lang(): void
    {
        FakeDriver::$detected = 'nl';

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nl', $this->body($response)['data']['attributes']['sourceLanguage']);
        $this->assertSame('nl', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));
    }

    #[Test]
    public function content_too_short_to_detect_is_not_learned_from_the_provider(): void
    {
        // "+1" is below short_content_length: stored as undetermined, and a provider's guess must not replace that.
        $this->database()->table('posts')->where('id', 2)->update(['content' => '<t><p>+1</p></t>', 'detected_lang' => '']);
        FakeDriver::$detected = 'en';

        $response = $this->translate('posts', 2, ['locale' => 'de']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));
    }

    #[Test]
    public function translation_types_have_no_standalone_routes(): void
    {
        $this->prepareDatabase(['post_translations' => [
            ['id' => 1, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Visible</p></t>', 'provider' => 'deepl'],
        ]]);

        $this->assertSame(404, $this->send($this->request('GET', '/api/post-translations/1', ['authenticatedAs' => 1]))->getStatusCode());
    }

    #[Test]
    public function driver_output_that_looks_like_s9e_xml_is_reparsed_not_stored(): void
    {
        FakeDriver::$echo = true;

        $this->prepareDatabase([Post::class => [
            ['id' => 9, 'discussion_id' => 1, 'number' => 9, 'user_id' => 3, 'type' => 'comment', 'detected_lang' => 'de',
                'content' => '<t>&lt;r&gt;&lt;URL url="javascript:alert(1)"&gt;click&lt;/URL&gt;&lt;/r&gt;</t>'],
        ]]);

        $response = $this->translate('posts', 9, ['locale' => 'en']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $html = $this->body($response)['data']['attributes']['contentHtml'];
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('&lt;URL', $html);
    }

    #[Test]
    public function source_over_the_length_cap_is_422_without_a_driver_call(): void
    {
        $this->setting('fof-translate.max_source_length', 10);

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('fof-translate.api.too_long', $this->body($response)['errors'][0]['detail']);
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function length_cap_of_zero_is_unlimited(): void
    {
        $this->setting('fof-translate.max_source_length', 0);

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
    }

    #[Test]
    public function a_failed_source_is_not_resent_while_negatively_cached(): void
    {
        FakeDriver::$fail = true;

        $this->assertSame(422, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertCount(1, FakeDriver::$requests);

        FakeDriver::$fail = false;

        $second = $this->translate('posts', 2, ['locale' => 'en']);
        $this->assertSame(422, $second->getStatusCode());
        $this->assertSame('fof-translate.api.translation_failed', $this->body($second)['errors'][0]['detail']);
        $this->assertCount(1, FakeDriver::$requests);

        // Other languages are unaffected.
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'fr'])->getStatusCode());
    }

    #[Test]
    public function hidden_opening_post_is_not_sent_as_context(): void
    {
        $this->database()->table('posts')->where('id', 1)->update(['hidden_at' => Carbon::now()]);

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());

        $this->assertStringContainsString('Ein deutscher Titel', (string) FakeDriver::$requests[0]->context);
        $this->assertStringNotContainsString('Das ist der erste Beitrag', (string) FakeDriver::$requests[0]->context);
    }

    #[Test]
    public function unconfigured_driver_reports_no_driver(): void
    {
        $this->setting('fof-translate.driver', null);

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('fof-translate.api.no_driver', $this->body($response)['errors'][0]['detail']);
    }

    #[Test]
    public function driver_reporting_the_target_language_stores_no_row_and_learns(): void
    {
        FakeDriver::$detected = 'en';

        $response = $this->translate('posts', 2, ['locale' => 'en']);

        $this->assertSame(422, $response->getStatusCode());
        $error = $this->body($response)['errors'][0];
        $this->assertSame('fof-translate.api.already_in_language', $error['detail']);
        $this->assertSame('/data/attributes/locale', $error['source']['pointer']);

        $this->assertSame(0, $this->database()->table('post_translations')->count());
        $this->assertSame('en', $this->database()->table('posts')->where('id', 2)->value('detected_lang'));

        // Learned: the next click is refused without a driver call.
        $this->assertSame(422, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertCount(1, FakeDriver::$requests);
    }

    #[Test]
    public function legacy_un_value_is_treated_as_unknown(): void
    {
        $this->database()->table('posts')->where('id', 2)->update(['detected_lang' => 'un']);

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertNull(FakeDriver::$requests[0]->sourceLanguage);
    }

    #[Test]
    public function error_pointers_separate_locale_problems_from_translation_failures(): void
    {
        $pointer = fn ($response) => $this->body($response)['errors'][0]['source']['pointer'];

        $this->assertSame('/data/attributes/locale', $pointer($this->translate('posts', 2, ['locale' => 'xx'])));
        $this->assertSame('/data/attributes/locale', $pointer($this->translate('posts', 2, ['locale' => 'de'])));
        $this->assertSame('/data/attributes/translation', $pointer($this->translate('posts', 4, ['locale' => 'en'])));

        FakeDriver::$fail = true;
        $this->assertSame('/data/attributes/translation', $pointer($this->translate('posts', 2, ['locale' => 'en'])));
    }

    #[Test]
    public function too_long_and_no_driver_point_at_translation(): void
    {
        $this->setting('fof-translate.max_source_length', 10);
        $this->assertSame('/data/attributes/translation', $this->body($this->translate('posts', 2, ['locale' => 'en']))['errors'][0]['source']['pointer']);
    }

    #[Test]
    public function no_driver_points_at_translation(): void
    {
        $this->setting('fof-translate.driver', null);
        $this->assertSame('/data/attributes/translation', $this->body($this->translate('posts', 2, ['locale' => 'en']))['errors'][0]['source']['pointer']);
    }
}
