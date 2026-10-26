<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate;

use Flarum\Api\Resource;
use Flarum\Audit\Extend\Audit as AuditLog;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\Post;
use FoF\Polls\Api\Resource\PollResource;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less')
        ->content(Content\AdminPayload::class),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())
        ->register(Provider\TranslateServiceProvider::class)
        ->register(Content\ContentServiceProvider::class),

    (new Extend\Model(Post::class))
        ->cast('detected_lang', 'string'),

    (new Extend\Model(Discussion::class))
        ->cast('detected_lang', 'string'),

    (new Extend\Event())
        ->listen(PostSaving::class, Listener\DetectPostLanguage::class)
        ->listen(DiscussionSaving::class, Listener\DetectDiscussionLanguage::class)
        ->subscribe(Listener\MarkTranslationsStale::class),

    (new Extend\Policy())
        ->globalPolicy(Access\GlobalPolicy::class),

    new Extend\ApiResource(Api\Resource\PostTranslationResource::class),
    new Extend\ApiResource(Api\Resource\DiscussionTranslationResource::class),

    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(Api\DetectedLangField::post(...))
        ->endpoints(fn () => [Api\TranslateEndpoint::post()]),

    (new Extend\ApiResource(Resource\DiscussionResource::class))
        ->fields(Api\DetectedLangField::discussion(...))
        ->endpoints(fn () => [Api\TranslateEndpoint::discussion(), Api\BulkTranslationsEndpoint::make()]),

    (new Extend\ApiResource(Resource\ForumResource::class))
        ->fields(Api\ForumResourceFields::class),

    (new Extend\Routes('api'))
        ->get('/fof-translate/deepl/glossaries', 'fof-translate.deepl.glossaries', Api\Controller\ListDeepLGlossariesController::class)
        ->get('/fof-translate/detection', 'fof-translate.detection.counts', Api\Controller\DetectionCountsController::class)
        ->post('/fof-translate/detection', 'fof-translate.detection.run', Api\Controller\RunDetectionController::class)
        ->post('/fof-translate/drivers/{name}/test', 'fof-translate.drivers.test', Api\Controller\TestDriverController::class),

    (new Extend\ThrottleApi())
        ->set('fof-translate.translate', Api\TranslateThrottler::class),

    (new Extend\Console())
        ->command(Console\DetectLanguagesCommand::class)
        ->command(Console\PruneCommand::class)
        ->command(Console\RepairXmlCommand::class),

    (new Extend\Settings())
        ->default('fof-translate.translate-all-enabled', true)
        ->default('fof-translate.use-native-locale-names', false)
        ->default('fof-translate.short_content_length', 10)
        ->default('fof-translate.throttle_per_minute', 30)
        ->default('fof-translate.max_source_length', 20000)
        ->default('fof-translate.glossary', '[]')
        ->default('fof-translate.deepl.formality', 'default')
        ->default('fof-translate.deepl.en_choice', 'en-US')
        ->default('fof-translate.deepl.pt_choice', 'pt-BR')
        ->default('fof-translate.deepl.model_type', 'prefer_quality_optimized')
        ->default('fof-translate.openai.model', 'gpt-4o')
        ->default('fof-translate.openai.max_tokens', 4096)
        ->default('fof-translate.openai.timeout', 30)
        ->default('fof-translate.claude.model', 'claude-opus-5-5')
        ->default('fof-translate.claude.effort', 'low')
        ->default('fof-translate.claude.timeout', 60)
        ->default('fof-translate.ollama.base_url', 'http://localhost:11434')
        ->default('fof-translate.ollama.timeout', 120)
        ->default('fof-translate.ollama.think', 'low')
        ->default('fof-translate.ai.en_variant', 'en-GB')
        ->default('fof-translate.ai.de_variant', 'casual')
        ->serializeToForum('fof-translate.useNativeLocaleNames', 'fof-translate.use-native-locale-names', 'boolval'),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-polls', fn () => [
            new Extend\ApiResource(Api\Resource\PollTranslationResource::class),
            new Extend\ApiResource(Api\Resource\PollOptionTranslationResource::class),

            (new Extend\ApiResource(PollResource::class))
                ->fields(Api\PollDetectedLangField::class)
                ->endpoints(fn () => [Api\TranslateEndpoint::poll()]),

            (new Extend\Event())
                ->subscribe(Listener\MarkPollTranslationsStale::class),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())
                ->addType(Gdpr\TranslationData::class),
        ])
        // Admin and moderator actions only: an ordinary translate click is not logged.
        ->whenExtensionEnabled('flarum-audit', fn () => [
            (new AuditLog())
                ->listen(Event\TranslationRefreshed::class, 'fof_translate.post_translation_refreshed', Audit\RefreshPayload::post(...))
                ->listen(Event\TranslationRefreshed::class, 'fof_translate.discussion_translation_refreshed', Audit\RefreshPayload::discussion(...))
                ->listen(Event\TranslationRefreshed::class, 'fof_translate.poll_translation_refreshed', Audit\RefreshPayload::poll(...))
                ->listen(Event\DriverTested::class, 'fof_translate.driver_tested', fn (Event\DriverTested $event) => [
                    'driver' => $event->driver,
                    'ok' => $event->ok,
                ])
                ->listen(Event\LanguageDetectionRun::class, 'fof_translate.language_detection_run', fn (Event\LanguageDetectionRun $event) => [
                    'type' => $event->type,
                    'force' => $event->force,
                    'rows' => $event->rows,
                    'source' => $event->source,
                ])
                ->listen(Event\TranslationsPruned::class, 'fof_translate.translations_pruned', fn (Event\TranslationsPruned $event) => [
                    'same_language' => $event->sameLanguage,
                    'orphaned_polls' => $event->orphanedPolls,
                    'orphaned_poll_options' => $event->orphanedPollOptions,
                ])
                ->listen(Event\TranslationsRepaired::class, 'fof_translate.translations_repaired', fn (Event\TranslationsRepaired $event) => [
                    'rows' => $event->rows,
                ]),
        ]),
];
