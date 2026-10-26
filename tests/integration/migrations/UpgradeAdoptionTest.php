<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration\migrations;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use FoF\Translate\Tests\integration\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Rebuilds an ianm/translate 1.x schema with data inside the test transaction, then
 * re-runs every fof-translate migration against it. Needs transactional DDL, so it
 * only runs on SQLite and PostgreSQL (MySQL would commit the DDL).
 */
class UpgradeAdoptionTest extends TestCase
{
    protected const TABLES = ['post_translations', 'discussion_translations', 'poll_translations', 'poll_option_translations'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    protected function schema(): Builder
    {
        return $this->database()->getSchemaBuilder();
    }

    protected function migrate(): void
    {
        $this->database()->table('migrations')->where('extension', 'fof-translate')->delete();

        /** @var ExtensionManager $manager */
        $manager = $this->app()->getContainer()->make(ExtensionManager::class);
        $manager->migrate($manager->getExtension('fof-translate'));
    }

    /**
     * @return array<string, list<string>>
     */
    protected function columns(): array
    {
        $columns = [];

        foreach (self::TABLES as $table) {
            $list = $this->schema()->getColumnListing($table);
            sort($list);
            $columns[$table] = $list;
        }

        return $columns;
    }

    protected function buildLegacySchema(): void
    {
        $schema = $this->schema();

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }

        // 2022_11_09 shape: before update_needed and edited_by were added.
        $schema->create('post_translations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('post_id')->unsigned();
            $table->string('language', 10);
            $table->mediumText('content')->nullable();
            $table->string('provider');
            $table->dateTime('created_at')->useCurrent()->nullable();
            $table->dateTime('updated_at')->useCurrent()->nullable();
            $table->unique(['post_id', 'language']);
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
        });

        // Final 1.x shape.
        $schema->create('discussion_translations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('discussion_id');
            $table->string('language', 10);
            $table->text('translation');
            $table->string('provider');
            $table->boolean('update_needed')->default(false);
            $table->timestamps();
            $table->unsignedInteger('edited_by')->nullable();
            $table->unique(['discussion_id', 'language']);
            $table->foreign('discussion_id')->references('id')->on('discussions')->onDelete('cascade');
        });

        // Poll tables absent: fof/polls was never installed on this forum.

        $db = $this->database();

        $db->table('post_translations')->insert(['id' => 7, 'post_id' => 2, 'language' => 'en', 'content' => '<t><p>Legacy</p></t>', 'provider' => 'deepl']);
        $db->table('discussion_translations')->insert(['id' => 9, 'discussion_id' => 1, 'language' => 'en', 'translation' => 'A German title', 'provider' => 'openai', 'update_needed' => 0, 'edited_by' => 1]);

        $db->table('group_permission')->where('permission', 'like', 'fof-translate.%')->delete();
        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'translateLocale'],
            ['group_id' => Group::MODERATOR_ID, 'permission' => 'translateAll'],
            ['group_id' => Group::MODERATOR_ID, 'permission' => 'refreshTranslation'],
            ['group_id' => Group::MODERATOR_ID, 'permission' => 'editTranslations'],
        ]);

        $db->table('settings')->where('key', 'like', 'fof-translate.%')->delete();
        $db->table('settings')->insert([
            ['key' => 'ianm-translate.provider', 'value' => 'deepl'],
            ['key' => 'ianm-translate.deepl.api_key', 'value' => 'secret:fx'],
            ['key' => 'ianm-translate.deepl.glossary_de_to_en', 'value' => 'gloss-1'],
            ['key' => 'ianm-translate.deepl.glossary_default', 'value' => 'none'],
            ['key' => 'ianm-translate.openai.translation_model', 'value' => 'gpt-4.1'],
            ['key' => 'ianm-translate.openai.en_variant', 'value' => 'en-US'],
            ['key' => 'ianm-translate.openai.detection_model', 'value' => 'gpt-3.5'],
            ['key' => 'ianm-translate.deepl.supported_languages', 'value' => '[]'],
            ['key' => 'ianm-translate.preload', 'value' => '1'],
            ['key' => 'ianm-translate.translate-all-enabled', 'value' => '0'],
        ]);
    }

    #[Test]
    public function upgraded_forum_is_adopted_and_converges_on_the_fresh_schema(): void
    {
        if ($this->database()->getDriverName() === 'mysql' || $this->database()->getDriverName() === 'mariadb') {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        $fresh = $this->columns();

        $this->buildLegacySchema();
        $this->migrate();

        $this->assertSame($fresh, $this->columns());

        $db = $this->database();

        $post = $db->table('post_translations')->where('id', 7)->first();
        $this->assertSame('<t><p>Legacy</p></t>', $post->content);
        $this->assertEquals(0, $post->update_needed);
        $this->assertNull($post->edited_by);
        $this->assertEquals(1, $db->table('discussion_translations')->where('id', 9)->value('edited_by'));
        $this->assertSame('de', $db->table('posts')->where('id', 2)->value('detected_lang'));

        $settings = $db->table('settings')->where('key', 'like', 'fof-translate.%')->pluck('value', 'key')->all();
        ksort($settings);
        $this->assertSame([
            'fof-translate.ai.en_variant' => 'en-US',
            'fof-translate.deepl.api_key' => 'secret:fx',
            'fof-translate.deepl.glossary_de_to_en' => 'gloss-1',
            'fof-translate.driver' => 'deepl',
            'fof-translate.openai.model' => 'gpt-4.1',
            'fof-translate.translate-all-enabled' => '0',
        ], $settings);
        $this->assertSame(10, $db->table('settings')->where('key', 'like', 'ianm-translate.%')->count());

        $grants = $db->table('group_permission')->where('permission', 'like', 'fof-translate.%')
            ->orderBy('permission')->get(['group_id', 'permission'])
            ->map(fn ($row) => $row->permission.':'.$row->group_id)->all();
        $this->assertSame([
            'fof-translate.refresh:'.Group::MODERATOR_ID,
            'fof-translate.translate:'.Group::MEMBER_ID,
            'fof-translate.translateAnyLanguage:'.Group::MODERATOR_ID,
        ], $grants);
        $this->assertSame(4, $db->table('group_permission')->whereIn('permission', ['translateLocale', 'translateAll', 'refreshTranslation', 'editTranslations'])->count());

        // Second run (e.g. re-enable) is a no-op.
        $this->migrate();

        $this->assertSame($fresh, $this->columns());
        $this->assertSame(3, $db->table('group_permission')->where('permission', 'like', 'fof-translate.%')->count());
        $this->assertSame(6, $db->table('settings')->where('key', 'like', 'fof-translate.%')->count());
        $this->assertSame(1, $db->table('post_translations')->count());
    }

    #[Test]
    public function google_free_provider_maps_to_the_google_driver(): void
    {
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        $this->buildLegacySchema();
        $this->database()->table('settings')->where('key', 'ianm-translate.provider')->update(['value' => 'google-free']);

        $this->migrate();

        $this->assertSame('google', $this->database()->table('settings')->where('key', 'fof-translate.driver')->value('value'));
    }

    #[Test]
    public function an_unknown_legacy_provider_is_not_copied(): void
    {
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        $this->buildLegacySchema();
        $this->database()->table('settings')->where('key', 'ianm-translate.provider')->update(['value' => 'some-removed-provider']);

        $this->migrate();

        $this->assertNull($this->database()->table('settings')->where('key', 'fof-translate.driver')->value('value'));
    }

    #[Test]
    public function upgraded_forum_with_a_revoked_grant_gets_no_defaults(): void
    {
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        $this->buildLegacySchema();
        // The admin had revoked translateLocale from everyone on 1.x.
        $this->database()->table('group_permission')->where('permission', 'translateLocale')->delete();

        $this->migrate();

        $this->assertFalse($this->database()->table('group_permission')->where('permission', 'fof-translate.translate')->exists());
    }

    #[Test]
    public function purge_drops_adopted_indexes_whatever_their_name(): void
    {
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        // Indexes as an older ianm/translate install named them, not Laravel's convention.
        foreach (['posts', 'discussions'] as $table) {
            foreach ($this->schema()->getIndexes($table) as $index) {
                if ($index['columns'] === ['detected_lang']) {
                    $this->schema()->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }

            $this->schema()->table($table, fn (Blueprint $blueprint) => $blueprint->index('detected_lang', "flarum_{$table}_detected_lang_index"));
        }

        /** @var ExtensionManager $manager */
        $manager = $this->app()->getContainer()->make(ExtensionManager::class);
        $manager->migrateDown($manager->getExtension('fof-translate'));

        $this->assertFalse($this->schema()->hasColumn('posts', 'detected_lang'));
        $this->assertFalse($this->schema()->hasColumn('discussions', 'detected_lang'));

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->schema()->hasTable($table), "$table was not dropped");
        }
    }

    protected function seedPurgeSettings(): void
    {
        $settings = $this->database()->table('settings');

        foreach ([
            'fof-translate.driver' => 'claude',
            'fof-translate.claude.api_key' => 'sk-ant-secret',
            'fof-translate.acme.api_key' => 'third-party-driver-secret',
            'ianm-translate.provider' => 'deepl',
            'other-extension.setting' => 'kept',
        ] as $key => $value) {
            $settings->updateOrInsert(['key' => $key], ['value' => $value]);
        }
    }

    #[Test]
    public function purge_deletes_every_fof_translate_setting_and_nothing_else(): void
    {
        if (in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires transactional DDL.');
        }

        $this->seedPurgeSettings();

        /** @var ExtensionManager $manager */
        $manager = $this->app()->getContainer()->make(ExtensionManager::class);
        $manager->migrateDown($manager->getExtension('fof-translate'));

        $keys = $this->database()->table('settings')->pluck('key')->all();

        $this->assertSame([], array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'fof-translate.'))));
        $this->assertContains('ianm-translate.provider', $keys);
        $this->assertContains('other-extension.setting', $keys);
    }

    #[Test]
    public function disabling_deletes_no_settings(): void
    {
        $this->seedPurgeSettings();

        /** @var ExtensionManager $manager */
        $manager = $this->app()->getContainer()->make(ExtensionManager::class);
        $manager->disable('fof-translate');

        $this->assertSame('sk-ant-secret', $this->database()->table('settings')->where('key', 'fof-translate.claude.api_key')->value('value'));
        $this->assertSame('third-party-driver-secret', $this->database()->table('settings')->where('key', 'fof-translate.acme.api_key')->value('value'));
    }

    #[Test]
    public function fresh_install_grants_default_permissions(): void
    {
        $this->database()->table('group_permission')->where('permission', 'like', 'fof-translate.%')->delete();
        $this->database()->table('group_permission')->whereIn('permission', ['translateLocale', 'translateAll', 'refreshTranslation'])->delete();

        $this->migrate();

        $grants = $this->database()->table('group_permission')->where('permission', 'like', 'fof-translate.%')
            ->orderBy('permission')->get()->map(fn ($row) => $row->permission.':'.$row->group_id)->all();

        $this->assertSame([
            'fof-translate.translate:'.Group::GUEST_ID,
            'fof-translate.translateAnyLanguage:'.Group::MODERATOR_ID,
        ], $grants);
    }
}
