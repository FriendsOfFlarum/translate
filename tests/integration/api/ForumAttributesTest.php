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

class ForumAttributesTest extends TestCase
{
    protected function forum(?int $as = null): array
    {
        $response = $this->send($this->request('GET', '/api', $as ? ['authenticatedAs' => $as] : []));

        $this->assertSame(200, $response->getStatusCode());

        return $this->body($response)['data']['attributes'];
    }

    #[Test]
    public function guest_gets_all_supported_forum_locales_by_default(): void
    {
        $attributes = $this->forum();

        $this->assertTrue($attributes['fof-translate.canTranslate']);
        $this->assertFalse($attributes['fof-translate.canRefresh']);
        $this->assertSame(['en', 'de', 'fr'], $attributes['fof-translate.targetLanguages']);
        $this->assertFalse($attributes['fof-translate.useNativeLocaleNames']);
    }

    #[Test]
    public function page_loads_never_ask_the_provider_and_construct_the_driver_at_most_once(): void
    {
        $this->seedForum();
        FakeDriver::$targets = ['de', 'ja'];

        // Unresolved: every forum locale is offered, unvalidated.
        $this->assertSame(['en', 'de', 'fr'], $this->forum()['fof-translate.targetLanguages']);
        $this->assertSame(['en', 'de', 'fr'], $this->forum(2)['fof-translate.targetLanguages']);

        $this->assertSame(0, FakeDriver::$targetCalls);
        $this->assertSame(1, FakeDriver::$constructed);
    }

    #[Test]
    public function a_translate_request_resolves_and_caches_the_real_target_list(): void
    {
        $this->seedForum();
        FakeDriver::$targets = ['de', 'en', 'ja'];

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertSame(1, FakeDriver::$targetCalls);

        $this->assertSame(['en', 'de'], $this->forum()['fof-translate.targetLanguages']);
        $this->assertSame(422, $this->translate('posts', 2, ['locale' => 'fr'])->getStatusCode());
        $this->assertSame(1, FakeDriver::$targetCalls);
    }

    #[Test]
    public function a_failed_listing_falls_back_to_forum_locales_and_is_not_retried_immediately(): void
    {
        $this->seedForum();
        FakeDriver::$targetsFail = true;

        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'en'])->getStatusCode());
        $this->assertSame(200, $this->translate('posts', 2, ['locale' => 'fr'])->getStatusCode());

        $this->assertSame(1, FakeDriver::$targetCalls);
        $this->assertSame(['en', 'de', 'fr'], $this->forum()['fof-translate.targetLanguages']);
    }

    #[Test]
    public function the_cache_key_holds_no_secrets(): void
    {
        $this->setting('fof-translate.fake.api_key', 'sk-super-secret');

        $manager = $this->app()->getContainer()->make(\FoF\Translate\Driver\DriverManager::class);
        $withKey = $manager->fingerprint('fake');

        $settings = $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->set('fof-translate.fake.api_key', 'sk-rotated');
        $this->assertSame($withKey, $manager->fingerprint('fake'));

        $settings->set('fof-translate.fake.api_key', '');
        $this->assertNotSame($withKey, $manager->fingerprint('fake'));

        $settings->set('fof-translate.fake.model', 'other');
        $this->assertNotSame($withKey, $manager->fingerprint('fake'));
    }

    #[Test]
    public function without_translate_any_language_only_the_current_locale(): void
    {
        $this->setting('fof-translate.translate-all-enabled', false);

        $this->assertSame(['en'], $this->forum()['fof-translate.targetLanguages']);
    }

    #[Test]
    public function without_permission_nothing_is_offered(): void
    {
        $this->database()->table('group_permission')->where('permission', 'fof-translate.translate')->delete();

        $attributes = $this->forum();

        $this->assertFalse($attributes['fof-translate.canTranslate']);
        $this->assertSame([], $attributes['fof-translate.targetLanguages']);
    }

    #[Test]
    public function admin_can_refresh(): void
    {
        $this->assertTrue($this->forum(1)['fof-translate.canRefresh']);
    }

    #[Test]
    public function no_driver_means_no_targets(): void
    {
        $this->setting('fof-translate.driver', null);

        $this->assertSame([], $this->forum()['fof-translate.targetLanguages']);
    }
}
