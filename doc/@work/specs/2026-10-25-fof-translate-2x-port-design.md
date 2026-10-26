# FoF Translate (2.x port of ianm/translate, on-demand only) — Design Spec

**Status:** in-progress (implemented and reviewed; not yet committed or released)
**Flarum target:** ^2.0
**Date:** 2026-10-25

## Goal

`fof/translate` is the open-source successor to `ianm/translate` 1.x. It ships one feature: **click-to-translate**. A reader presses a button on a post, a discussion title or a poll, and the translation appears for them alone; it is fetched from the configured driver and cached in the database. A forum that ran `ianm/translate` on 1.x installs `fof/translate` after upgrading to Flarum 2 and keeps its cached translations, detected languages, settings, API keys and permission grants.

Done means: an upgraded forum shows the same cached translations with zero re-translation cost, a fresh forum works with any of the four drivers, and the extension adds **no queries and no provider calls** to page loads that don't involve a click.

## Non-Goals

Dropped completely. Proprietary to the 1.x vendor, or exists only to support seamless/preload mode:

- Seamless/preload mode in every form: the `translation` (locale-based HasOne) relation, auto-included translations, in-place content replacement, `/{lang}/d/{id}` routes, `SetPathLocale` / `GuestLocaleMiddleware` / `LanguageParamPath` / `OverrideLocaleHeader` / `BindBrowserLanguage` middleware, hreflang and meta headers, the `overrides/` directory, SEO (v17development/flarum-seo), the `fof/sitemap` per-language resources and robots entries, title truncation (`extendApplication.updateTitle`), translated titles in lists, notifications and search sources, and fof/synopsis, flarum/sticky and fof/best-answer integrations.
- Multi-language full-text search over translations (`FullTextGambitWithTranslations`). With on-demand caching the index only ever covers what someone happened to click.
- Manual translation editing (EditTranslatedPostComposer, the Manage*TranslationsModals, `POST /translate/manual`). Every entry point was gated on seamless mode. Rows a human edited (`edited_by IS NOT NULL`) are still **honoured and never auto-overwritten**.
- Eager pre-translation: the after-save job into every locale, plus `translate:all`, `translate:batch` and `translate:status`. That cost is incurred only for seamless.
- All `glowingblue/*` integrations (quiz, knowledgebase, products, localizd). Quiz tables are left untouched in the DB.
- fof/polls **poll groups** translation (group names). Deferred; the `poll_group_translations` table is left as-is.

## Architecture

**One translation service, four drivers, zero page-load cost.** `TranslationService::translate(Translatable $subject, string $targetLocale, User $actor, bool $refresh)` does this:

1. Looks up the cached row with one query on the existing `UNIQUE(subject_id, language)` index.
2. Returns the row if it's fresh, or if a human edited it.
3. Otherwise builds the source text through the subject's adapter (unparse, then protect markup), calls the driver, restores the markup, re-parses with the post formatter as the post author, upserts the row and returns it.

Drivers are swappable services behind a small `TranslationDriver` interface, registered through an extender so third parties can add their own. Nothing resolves a driver unless a translation is actually requested. The forum payload's target-language list is served from the Flarum cache, not from a provider call.

**Detection is local and self-correcting.** `nitotm/efficient-language-detector` (ELD) runs on its **full language set**, with no subset. That way a Chinese post on an EN/DE forum is detected as `zh` and offers translation to both audiences.

- **When it runs:** synchronously on post save (content dirty) and discussion save (title dirty). It takes well under a millisecond, with no network or queue.
- **Unreliable results:** stored as `NULL`, which means unknown and shows the button.
- **Self-correction:** when a driver translates, it reports the source language it saw. If that differs from `detected_lang`, the column is updated, so bad guesses correct themselves and old posts backfill as they're read.

**Staleness is lazy.** On `Post\Revised`, `Discussion\Renamed` and a poll update, one `UPDATE … SET update_needed = 1 WHERE subject_id = ? AND edited_by IS NULL` runs. There are no jobs and no API calls. The next click re-translates.

Alternatives rejected:
- *Keep the 1.x adapter/provider class hierarchy as-is.* It mixes detection, the glossary, seamless branches and five entity types into one 670-line abstract class. A service plus thin drivers is smaller and testable with a fake driver.
- *Queue-based detection.* On Flarum's default sync queue it still runs in-request, and it still bills a full provider translation per save. In 1.x DeepL "detected" by translating the whole post to English and discarding the result.

## Upgrade path from ianm/translate (data retention)

Flarum computes pending migrations up front per extension ID (`Migrator::run()` → `getRan($extension->getId())`). So every `fof-translate` migration runs on an upgraded forum even though the tables exist. **Every migration therefore adopts or creates**: it checks `hasTable` / `hasColumn` / index existence and creates only what's missing. A fresh install and an upgraded install converge on the same schema.

User flow (README):
1. Upgrade Flarum to 2.x.
2. `composer remove ianm/translate` (removing via Composer does *not* run its down-migrations; **do not** use admin "Uninstall → purge").
3. `composer require fof/translate`.
4. Enable it.

`composer.json` declares `"conflict": {"ianm/translate": "*"}`.

Data migrations, all idempotent, copy-only and non-destructive:

| Old | New | How |
|---|---|---|
| Settings `ianm-translate.<key>` (whitelist below) | `fof-translate.<key>` | Insert if the new key is absent. Provider names map to driver names: `deepl`, `openai`, and `google-free` → `google`. |
| Permissions `translateLocale`, `translateAll`, `refreshTranslation` | `fof-translate.translate`, `fof-translate.translateAnyLanguage`, `fof-translate.refresh` | `INSERT … SELECT` from `group_permission` where not already present. Old rows are left alone. |
| `editTranslations` | — | Dropped (manual editing removed). |
| Env `IANM_TRANSLATE_{DRIVER}_API_KEY` | `FOF_TRANSLATE_{DRIVER}_API_KEY` | Read new first, fall back to old. No data migration. |

Copied settings whitelist: `provider`, `deepl.api_key`, `deepl.formality`, `deepl.en_choice`, `deepl.model_type`, `deepl.glossary_{src}_to_{tgt}`, `openai.api_key`, `openai.translation_model` (→ `openai.model`), `openai.de_variant` / `openai.en_variant` (→ `ai.de_variant` / `ai.en_variant`, now shared by all AI drivers), `openai.max_tokens`, `openai.timeout`, `glossary`, `translate-all-enabled`, `use-native-locale-names`. Cached `*.supported_languages` is not copied; it is regenerated into the cache store.

**Purge:** the final migration (`2026_09_09_000010_remove_settings_on_purge`) does nothing on the way up. Its down step deletes every `fof-translate.*` setting, including stored API keys and third-party drivers' keys, because core's uninstall never removes extension settings. Disabling runs no down steps, so it keeps them. Legacy `ianm-translate.*` settings and grants are left alone.

User preference `ianm-translate.labelAllSource` is not migrated. In 1.x on-demand mode the label it controlled rendered nothing (`DetectedLanguageLabel` only rendered with a preloaded translation). It is superseded by the translate button naming the source language.

## Data Model

Final 1.x schema, adopted or created. Models: `PostTranslation`, `DiscussionTranslation`, `PollTranslation`, `PollOptionTranslation` (namespace `FoF\Translate\Model`).

**`posts.detected_lang`** and **`discussions.detected_lang`**: `string(2) NULL`, indexed. Matches 1.x. ELD emits ISO 639-1 codes. The index is kept for the `fof:translate:detect` backfill command (`WHERE detected_lang IS NULL`).

**`post_translations`**

| column | type |
|---|---|
| id | increments |
| post_id | unsignedInteger, FK posts.id cascade |
| language | string(10) |
| content | mediumText NULL (s9e XML) |
| provider | string |
| update_needed | boolean default false |
| edited_by | unsignedInteger NULL, FK users.id set null |
| created_at / updated_at | dateTime NULL |
| | UNIQUE(post_id, language) |

**`discussion_translations`**: same shape with `discussion_id` and `translation` text.

**`poll_translations`**: poll_id, language, question, subtitle NULL, image_alt NULL, provider, update_needed, edited_by, timestamps, UNIQUE(poll_id, language).

**`poll_option_translations`**: option_id, language, answer, provider, update_needed, edited_by, timestamps, UNIQUE(option_id, language).

Poll tables are created **unconditionally**. FKs to `polls` / `poll_options` are added only if those tables exist at migrate time, so installing fof/polls later still works. Orphans are harmless: lookups go through the parent id. `fof:translate:prune` removes them.

**Full-text indexes** `post_translations_fulltext_content` and `discussion_translations_fulltext_translation` (1.x, MySQL only) are **dropped** if present. Nothing reads them now, and they add write cost to every translation insert. No data is lost.

**Polls' language** is *not* stored on `polls` (adding columns to another extension's table only works if it was installed first). A poll attached to a post uses its post's `detected_lang`; a global poll offers translation unconditionally. The existing `polls.detected_lang` column on upgraded forums is left untouched and unused.

Left untouched on upgraded forums, neither read nor dropped: `quiz_translations`, `quiz_option_translations`, `poll_group_translations`, `quizzes.detected_lang`, `poll_groups.detected_lang`.

## API Surface

No new fields or relationships on lists beyond one scalar.

- `PostResource` field `detectedLang` (Str, nullable, CommentPost only). `DiscussionResource` field `detectedLang`.
- `ForumResource` fields, computed once per request:
  - `fof-translate.canTranslate` (bool)
  - `fof-translate.targetLanguages` (list: forum locales ∩ driver targets; when the actor lacks `translateAnyLanguage`, only the actor's current locale)
  - `fof-translate.canRefresh` (bool)
  - `fof-translate.useNativeLocaleNames` (bool)
- The 1.x per-discussion `canTranslate` is removed; it was actor-level, recomputed for every discussion row.
- No `translations` / `translation` relationships on any resource (FND-123: don't replicate the dead all-locales include).

Translate endpoints are custom `Endpoint\Endpoint`s mounted on the subject's resource. Mounting there means the subject is resolved through that resource's visibility scope, so hidden or private posts 404 for free. Each response is serialized as the translation resource through a small `JsonApiResponse` helper, following fof/ban-ips.

| Method + path | Returns |
|---|---|
| `POST /api/posts/{id}/translate` body `{locale, refresh?}` | `post-translations` |
| `POST /api/discussions/{id}/translate` | `discussion-translations` |
| `POST /api/polls/{id}/translate` (Conditional on fof-polls) | `poll-translations` including `options` (`poll-option-translations`) |
| `POST /api/discussions/{id}/translations` body `{locale, posts: [ids]}` (max 50) | a `data` list of the cached, current `discussion-translations` (title) and `post-translations` (with `postId`) the reader may see. A read: it never calls a provider. Same visibility as the per-item endpoints (subject scope + source-field visibility), and counted by the same throttle |

POST rather than 1.x's GET: each call may spend money, so it must not be triggerable cross-site or by prefetchers. Each endpoint carries `->can('fof-translate.translate')` and validates `locale` ∈ the actor's target languages.

Translation resources (`AbstractDatabaseResource`, show-only, no Index/Create/Update/Delete endpoints):
- `post-translations`: `language`, `contentHtml` (rendered with the post's formatter on serialize), `provider`, `sourceLanguage`, `updatedAt`, `isStale`, `isEdited`.
- `discussion-translations`: `language`, `title`, `provider`, `sourceLanguage`, `updatedAt`.
- `poll-translations` / `poll-option-translations`: question/subtitle/imageAlt; answer.

`ThrottleApi` is applied to the translate routes: per actor (per IP for guests), N per minute, default 30, setting `fof-translate.throttle_per_minute`. It caps cost if a guest or bot hammers the button.

## Drivers

`interface TranslationDriver { name(); isConfigured(); translate(TranslationRequest): TranslationResult; targetLanguages(): array; }`

- `TranslationRequest`: `text`, `sourceLanguage?`, `targetLanguage`, `context?` (discussion title + first post excerpt), `glossary`.
- `TranslationResult`: `text`, `detectedSourceLanguage?`.

Registered via `(new FoF\Translate\Extend\TranslationDriver())->add(MyDriver::class)`. The admin picks one (`fof-translate.driver`).

| Driver | Transport | Notes |
|---|---|---|
| **Claude** (new, recommended) | `anthropic-ai/sdk` ^1.3, `$client->beta->messages->create()` | Model setting default `claude-opus-5-5`; selectable Opus 5.5 / Sonnet 5.5 / Haiku 5.5. Thinking can't be disabled on Opus 5.5, so `outputConfig.effort` defaults to `low` (translation is a simple task). Structured output `json_schema` `{translation, source_language}` gives the detected source in the same call. `fallbacks: 'default'` + beta `server-side-fallback-2026-07-01` on Opus 5.5 / Sonnet 5.5. `stopReason === 'refusal'` raises a translation error and nothing is cached. Timeout setting. |
| **OpenAI** (ported) | `openai-php/client` | Structured JSON output returns the same `{translation, source_language}` shape. The separate detection model and its call are removed. |
| **Ollama** (new, self-hosted) | Native `POST {base_url}/api/chat` via Flarum's Guzzle, with no extra dependency | Settings: `base_url` (default `http://localhost:11434`), `model` (required, free text), `timeout`, optional bearer token for reverse-proxied installs. `format` = JSON schema; `stream: false`. Target languages = forum locales, since any LLM can target them. |
| **DeepL** (ported) | `deeplcom/deepl-php` | Keeps the 1.x `#103` fixes (unparse before sending, protection rules, glossary pairs, formality/model type). Source detection comes from `detectedSourceLang` on the same call. |

All AI drivers share one prompt builder: preserve Markdown/BBCode, never translate protected placeholders, return only JSON. The placeholder/protection pipeline (`PlaceholderManager` + `Extend\TranslationProtection`) is kept and is driver-agnostic.

Drivers are only resolved when a translation is requested. `targetLanguages()` is cached in the Flarum cache for 24h and invalidated when the driver setting or its key changes.

## Permissions

| Key | Default | Meaning |
|---|---|---|
| `fof-translate.translate` | Guest | May translate into own current locale. |
| `fof-translate.translateAnyLanguage` | Moderator | May pick any forum locale. Setting `fof-translate.translate-all-enabled` (default true, as 1.x) grants this to everyone holding `translate`. |
| `fof-translate.refresh` | Admin only | Force re-translation even if fresh. Never overrides human-edited rows. |

Upgraded forums get their existing grants copied (see Upgrade path).

## Frontend

The forum bundle stays small; translation UI is a handful of components.

- **Post** (`CommentPost`):
  - **Button:** `actionItems` gets a translate button. With a single target it names the target ("Translate to English"); with several it becomes a "Translate from Chinese" dropdown. It's shown when `canTranslate`, the post isn't hidden, and `detectedLang` is null or differs from the reader's current locale. Content already in the reader's language never gets a translate control, whatever the actor's permissions: `translateAnyLanguage` only widens the target list on content the reader can't read. The source language itself is never a target.
    - **Short content:** content shorter than `short_content_length` takes its author's forum locale (their saved `locale` preference if installed, else `default_locale`), at save time and in the backfill. It never takes the editing moderator's locale. Long-but-ambiguous content and content with no text (link or image only) stay undetermined. Thread language is deliberately not used, because readers reply in any language once translation exists.
    - **Learning:** learn-on-translate never overwrites content shorter than `short_content_length`, since a provider's guess there is no better than the author's locale.
  - **Rendering:** the translation renders below the body via `extend(CommentPost.prototype, 'content', push…)`. FND-200 explains why: the header/body seam is gone in 2.x.
  - **Translation block:** shows "Translated from X to Y · provider · time", with close, refresh (permission) and a loading state.
- **Discussion**: the hero title is swapped in place (one `h1`, with `lang` set) rather than stacked.
  - **Status line:** a one-line `TranslationStatus` sits underneath: "Translated from Welsh · Show original · ↻". The provider and age are in its tooltip.
  - **Placement and colours:** the control and the status line sit on their own centred line, and inherit the hero's text colour so they read on tag-coloured heroes.
  - **Loading:** the button carries its own spinner, so the hero doesn't shift.
  - **Tab title:** while the translation is shown, `app.setTitle()` follows it; "Show original" reverts it.
  - **Whole thread:** the hero's translate action translates the title and every loaded comment post not in the reader's language. It does one bulk lookup for cached translations first, then translates the misses through the per-item endpoints, at most 3 at a time, with post failures summed into one alert. "Show original" on the hero reverts the title and every post. Posts loaded later by scrolling are not included.
  - **First post:** translating the first post also translates the title through `TranslationState.onTranslate`. That title request is quiet (no error alert of its own), so a failure surfaces only the post's error.
- **Polls** (only when `'fof-polls' in flarum.extensions`):
  - **Button:** a translate control is added to `AbstractPoll.headerItems`. It's imported from `ext:fof/polls/forum/components/Poll/AbstractPoll`; confirmed registered in the 3.x bundle.
  - **Display:** when active, the question/subtitle and each `PollOption.textItems` answer are swapped for the translation, with a "Show original" toggle. Swapping in place (rather than rendering a duplicate poll) keeps voting usable.
- **State:**
  - `TranslationState` instances live in a module-level `WeakMap<Model, TranslationState>`. A post scrolled out and back in keeps its open translation, and GC reclaims it with the model.
  - The `CommentPost` subtree check includes the state's version counter.
  - Translation records live in `app.store` under their resource types.
- **Admin**: `Extend.Admin().setting()` for simple settings (FND-148) plus a driver section that renders per-driver fields. Glossary editor ported (terms never translated). Defaults are registered in PHP only (FND-218).
- `LangDisplayName` (Intl.DisplayNames) is ported unchanged.

## Settings

Defaults are registered with `Extend\Settings()->default()`. Mind FND-017: nothing here is destructive or scheduled.

`fof-translate.driver` (default unset, so the admin must choose; Open Question 1), `fof-translate.translate-all-enabled` (true), `fof-translate.use-native-locale-names` (false), `fof-translate.short_content_length` (10: below this, the source is treated as unknown and not detected), `fof-translate.throttle_per_minute` (30), `fof-translate.glossary` (`[]`), plus per-driver keys `fof-translate.{deepl,openai,claude,ollama}.*`. Serialized to the forum: `useNativeLocaleNames` only (the rest are ForumResource fields above).

## Locale

`locale/en.yml` under `fof-translate.{admin,forum,lib}`. 1.x de/fr/it files are **not** carried; they translate the old key tree. FoF translations come via weblate/linguist.

## Cross-Extension Integration

- Hard `require`: `flarum/core ^2.0`, `nitotm/efficient-language-detector ^3.2`, `deeplcom/deepl-php ^1.20`, `openai-php/client ^0.21`, `anthropic-ai/sdk ^1.3`. Guzzle comes with core.
- `require-dev`: `flarum/testing ^2.0`, `flarum/phpstan ^2.0`, `fof/polls ^3.0`, `flarum/gdpr ^2.0`, `flarum/audit ^2.0`.
- `optional-dependencies`: `fof/polls`, `flarum/gdpr`, `flarum/audit`, for boot order (FND-026).
- One `Extend\Conditional` (FND-189):
  - `fof-polls`: the poll resources, endpoint, staleness listener and frontend flags.
  - `flarum-gdpr`: the `Gdpr\TranslationData` type. Export writes one JSON file per translated post or discussion the user authored, with the source unparsed. Anonymize does nothing, since the content stays. Delete runs after core's `Posts` type and removes every translation row whose source is gone. It does not rely on foreign keys, which poll tables may lack.
  - `flarum-audit`: our own events (`src/Event/`) are logged as `fof_translate.*` actions. These are refreshes (one action per subject type, carrying `discussion_id` so the entry shows in that discussion's log), driver tests, detection runs (once per run, never per batch), prunes and XML repairs. Ordinary translate clicks are not logged: they are high in volume and carry no accountability.
- `conflict`: `ianm/translate`.

## Console

- `fof:translate:detect [--posts|--discussions] [--force] [--chunk=500]`: local ELD backfill, chunked by id, one `UPDATE` per chunk via `CASE`. No API calls.
- `fof:translate:prune [--dry-run]`: deletes translations whose `language` equals the subject's `detected_lang`, and orphaned poll rows. This replaces 1.x `translate:cleanup`.
- `fof:translate:repair-xml [--fix] [--since=]`: ported from 1.x `#103` so upgraded forums can mark DeepL-corrupted rows `update_needed`.

## Testing Surface

Integration (PHPUnit 12, `#[Test]` attributes, skeleton phpunit XMLs, FND-154; a `FakeDriver` bound in the container so no network is used):

1. **Upgrade adoption**:
   - Build the 1.x schema plus seeded rows (translations, `detected_lang`, `ianm-translate.*` settings, old permission grants).
   - Enable `fof-translate`. Migrations succeed, data is intact, settings and grants are copied, and full-text indexes are dropped on MySQL.
   - A second enable is a no-op (idempotent).
2. **Fresh install schema** equals the adopted schema (column list per table).
3. Post translate: a cache miss calls the driver once and stores the row; a hit makes zero driver calls; a stale row re-translates; `refresh` without permission is ignored; an edited row is never re-translated; a hidden post 404s; a locale outside the target list returns 422; a guest without permission gets 403.
4. Learn-on-translate updates `detected_lang` when the driver reports a different source.
5. Detection on save sets `detected_lang` for reliable text, `NULL` for short or unreliable text, and makes no driver calls.
6. Revise/rename marks only non-edited rows stale, in one query.
7. N+1 / query budget: `GET /api/posts?filter[discussion]=…` and `GET /api/discussions` issue the same query count with the extension enabled as without.
8. Polls (fof/polls enabled): poll + option translation in one response; a hidden poll 404s.
9. Throttle returns 429 after N translate calls.
10. Driver unit tests: Claude/OpenAI/Ollama request building and JSON parsing against recorded fixtures; Claude refusal raises an error and caches nothing.

Frontend (Jest): `TranslateButton` target filtering; `TranslationState` survives remount through the WeakMap.

## Open Questions

1. ~~GoogleFree driver~~: **resolved, reinstated** as `google` ("Google Translate (free, unofficial)"), listed last.
   - **Live spike findings:** Google preserved placeholders and line structure, but translated indented code blocks, changed identifiers (`snake_case` → `Snake_case`), occasionally dropped emphasis markers, and stripped hard-break trailing spaces.
   - **Fixes for all drivers:** ContentProtector protects indented code blocks and underscore identifiers.
   - **Google-only safety layer:**
     - emphasis delimiters, line-start markers and hard-break spaces are swapped for placeholders before sending;
     - every response is verified (each placeholder exactly once, open before close, same line count and line structure);
     - on failure it retries per paragraph, then translates only the plain-text runs between protected parts, so structure is identical by construction;
     - a request cap fails closed, so an unverified translation is never stored.
2. **Default Claude model**: `claude-opus-5-5` is the highest-quality default ($4/$20 per MTok). Haiku 5.5 ($0.10/$0.50) is about 40x cheaper on input. Translation is high-volume, so the admin chooses; the question is which is preselected.
3. **OpenAI default model**: 1.x defaulted to `gpt-4o`. Keep it for upgraders (copied) and use the same for fresh installs, or update it to a current model id the maintainer picks.

## References

- Old extension: `../flarum-ext-translate` (branch 1.x, HEAD `5bd02f5`).
- Core: `framework/framework/core/src/Database/Migrator.php` (pending-migration computation), `Api/Endpoint/Endpoint.php` (custom endpoint serialization), `Api/Resource/PostResource.php`.
- `vendor/fof/ban-ips/src/Api/JsonApiResponse.php`: serializing another type from a custom endpoint.
- fof/polls 3.0.1 forum exports: `forum/components/Poll/AbstractPoll`, `PollOption`, `forum/models/Poll`, `PollOption`.
- Findings: FND-017, FND-026, FND-108, FND-123, FND-124, FND-147, FND-148, FND-154, FND-165, FND-189, FND-200, FND-203, FND-218.
