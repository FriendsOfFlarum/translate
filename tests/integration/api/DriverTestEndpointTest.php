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

use Flarum\User\User;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\fixtures\SettingsFakeDriver;
use FoF\Translate\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class DriverTestEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    protected function test(string $driver, ?array $settings = null, int $as = 1): ResponseInterface
    {
        return $this->send($this->request('POST', "/api/fof-translate/drivers/$driver/test", [
            'authenticatedAs' => $as,
            'json' => $settings === null ? [] : ['settings' => $settings],
        ]));
    }

    #[Test]
    public function non_admins_get_403(): void
    {
        $this->assertSame(403, $this->test('fake', null, 2)->getStatusCode());
        $this->assertCount(0, FakeDriver::$requests);
    }

    #[Test]
    public function unknown_driver_is_404(): void
    {
        $this->assertSame(404, $this->test('nope')->getStatusCode());
    }

    #[Test]
    public function success_translates_a_sample_into_the_admin_locale(): void
    {
        $response = $this->test('fake');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = $this->body($response)['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame('fake', $data['driver']);
        $this->assertIsInt($data['durationMs']);
        $this->assertSame('en', $data['target']['language']);
        $this->assertSame('de', $data['source']['language']);
        $this->assertStringContainsString('`php flarum cache:clear`', $data['source']['text']);
        $this->assertStringStartsWith('[en] Dies ist ein **kurzer Test**', $data['translation']['text']);
        $this->assertStringContainsString('href="https://flarum.org"', $data['translation']['html']);
        $this->assertTrue($data['markupPreserved']);
        $this->assertNull($data['error']);

        $this->assertCount(1, FakeDriver::$requests);
        $this->assertFalse(FakeDriver::$requests[0]->isPlainText);
        $this->assertSame('de', FakeDriver::$requests[0]->sourceLanguage);
        $this->assertContains('Flarum', FakeDriver::$requests[0]->glossary);
    }

    #[Test]
    public function source_sample_differs_from_a_german_admin_locale(): void
    {
        $this->database()->table('users')->where('id', 1)->update(['preferences' => json_encode(['locale' => 'de'])]);

        $data = $this->body($this->test('fake'))['data'];

        $this->assertSame('de', $data['target']['language']);
        $this->assertSame('en', $data['source']['language']);
    }

    #[Test]
    public function unsaved_settings_are_applied_for_the_request_only(): void
    {
        $response = $this->test('fake-settings', [
            'fof-translate.fake-settings.model' => 'unsaved-model',
            'fof-translate.ai.en_variant' => 'en-US',
            'fof-translate.driver' => 'deepl',
            'fof-translate.fake.model' => 'other-driver',
            'forum_title' => 'Hijacked',
        ]);

        $data = $this->body($response)['data'];
        $this->assertTrue($data['ok'], (string) $response->getBody());
        $this->assertSame('de', $data['detectedSourceLanguage']);

        $this->assertCount(1, SettingsFakeDriver::$calls);
        $this->assertSame('unsaved-model', SettingsFakeDriver::$calls[0]['model']);
        $this->assertSame('en-US', SettingsFakeDriver::$calls[0]['enVariant']);
        // The shared prompt builder received the overlay too.
        $this->assertStringContainsString('American English', SettingsFakeDriver::$calls[0]['prompt']);

        $stored = $this->database()->table('settings')->whereIn('key', [
            'fof-translate.fake-settings.model', 'fof-translate.ai.en_variant', 'fof-translate.fake.model', 'forum_title',
        ])->pluck('value', 'key')->all();
        $this->assertArrayNotHasKey('fof-translate.fake-settings.model', $stored);
        $this->assertArrayNotHasKey('fof-translate.ai.en_variant', $stored);
        $this->assertArrayNotHasKey('fof-translate.fake.model', $stored);
        $this->assertNotSame('Hijacked', $stored['forum_title'] ?? null);
        $this->assertSame('fake', $this->database()->table('settings')->where('key', 'fof-translate.driver')->value('value'));
        $this->assertSame(0, $this->database()->table('post_translations')->count());
    }

    #[Test]
    public function non_prefixed_keys_are_ignored(): void
    {
        // Only another driver's key is supplied: fake-settings stays unconfigured.
        $data = $this->body($this->test('fake-settings', ['fof-translate.fake.model' => 'x', 'model' => 'x']))['data'];

        $this->assertFalse($data['ok']);
        $this->assertCount(0, SettingsFakeDriver::$calls);
    }

    #[Test]
    public function driver_failure_is_reported_with_the_key_redacted(): void
    {
        $response = $this->test('fake-settings', [
            'fof-translate.fake-settings.model' => 'explode',
            'fof-translate.fake-settings.api_key' => 'sk-very-secret-key',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $data = $this->body($response)['data'];
        $this->assertFalse($data['ok']);
        $this->assertNull($data['translation']);
        $this->assertNull($data['markupPreserved']);
        $this->assertSame('Unauthorized for key [redacted]', $data['error']);
        $this->assertStringNotContainsString('sk-very-secret-key', (string) $response->getBody());
    }

    #[Test]
    public function not_configured_lists_missing_settings_without_calling_the_provider(): void
    {
        $data = $this->body($this->test('fake-settings'))['data'];

        $this->assertFalse($data['ok']);
        $this->assertSame('Missing required settings: model', $data['error']);
        $this->assertSame('de', $data['source']['language']);
        $this->assertNull($data['translation']);
        $this->assertCount(0, SettingsFakeDriver::$calls);
    }

    #[Test]
    public function it_is_not_throttled(): void
    {
        $this->setting('fof-translate.throttle_per_minute', 1);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->test('fake')->getStatusCode());
        }
    }
}
