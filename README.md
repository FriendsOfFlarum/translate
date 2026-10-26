# Translate by FriendsOfFlarum

[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/FriendsOfFlarum/translate/blob/main/LICENSE.md) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/translate.svg)](https://packagist.org/packages/fof/translate) [![Total Downloads](https://img.shields.io/packagist/dt/fof/translate.svg)](https://packagist.org/packages/fof/translate) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate)

A [Flarum](https://flarum.org) extension. Click-to-translate for posts, discussion titles and polls, powered by Claude, OpenAI, Ollama or DeepL.

This is the successor to `ianm/translate`. If you are coming from it, read [Upgrading from ianm/translate](#upgrading-from-ianmtranslate) before installing.

## Features

- A **Translate** button on posts, discussion titles and [fof/polls](https://github.com/FriendsOfFlarum/polls) polls (when fof/polls is installed). The translation appears only for the reader who asked for it.
- With a single target language the button names it ("Translate to English"). When the reader may translate into more than one language, it opens a language menu ("Translate from Chinese"). Content already in the reader's own language gets no button.
- **Translate a whole discussion at once:** the translate button under a discussion's title translates the title and every post on the page that isn't in the reader's language. Already-translated posts arrive in one request; the rest are translated a few at a time. **Show original** reverts the whole discussion.
- Translations are cached in the database. Only the first request for a given post and language goes to the translation service. Later requests are served from the cache.
- Post and discussion title languages are detected locally when content is saved. Detection makes no API calls.
- Editing a post, renaming a discussion or updating a poll marks its cached translations as stale. They are re-translated the next time someone asks. Nothing is re-translated in the background.
- Translations that a human edited in `ianm/translate` are kept and never overwritten automatically.
- A glossary of terms that are never translated, such as your forum or product name.
- Formatting (Markdown, BBCode, mentions, code) is preserved. Developers can protect custom BBCode too.
- Per-user rate limiting to keep provider costs under control.
- Works with [flarum/gdpr](https://github.com/flarum/gdpr) (data export and erasure) and [flarum/audit](https://github.com/flarum/audit) (admin actions are logged).

## Installation

Install with composer:

```sh
composer require fof/translate:"*"
php flarum migrate
php flarum cache:clear
```

Then enable **FoF Translate** in the admin panel, select a driver and enter its settings.

Composer may ask whether you trust `php-http/discovery` to run code. The DeepL, OpenAI and Claude SDKs depend on it. Answering `y` is safe, and so is `n`: Flarum already provides the HTTP client the plugin would otherwise install. To answer once for good:

```sh
composer config allow-plugins.php-http/discovery true
```

## Updating

```sh
composer update fof/translate --with-dependencies
php flarum migrate
php flarum cache:clear
```

## Uninstalling

Disabling the extension keeps all of its data. Uninstalling with **purge** from the admin panel removes everything fof/translate stores: the cached translations, the detected languages on posts and discussions, its permission grants, and every `fof-translate.*` setting, including API keys saved in the settings. API keys supplied through environment variables are not stored, so they are unaffected. Settings and grants left by `ianm/translate` are not touched.

## Configuration

Open the extension's settings page in the admin panel. Nothing is translated until a **translation driver** is selected and configured.

### Drivers

| Driver | Best for | Settings |
| --- | --- | --- |
| **Claude** (recommended) | Highest translation quality, context-aware | API key, model, effort, timeout |
| **OpenAI** | Forums already using OpenAI | API key, model (default `gpt-4o`), maximum tokens, timeout |
| **Ollama** (self-hosted) | Keeping content on your own servers | Server URL, model, optional API token, timeout |
| **DeepL** | Dedicated machine translation with glossaries | API key, formality, English variant, Portuguese variant, model type, glossaries |
| **Google Translate** (free, unofficial) | Trying the extension out, small forums | None |

#### Claude

- **Model:** Opus 5.5 (default), Sonnet 5.5 or Haiku 5.5. Opus gives the best translations and Haiku is the cheapest, with Sonnet in between. Translation volume grows with your traffic, so weigh quality against cost for your forum.
- **Effort:** low (default), medium or high. This controls how much the model reasons before answering. Low is usually enough for translation and costs the least.
- **Timeout:** in seconds (default 60).

#### OpenAI

- **Model:** any OpenAI chat model ID (default `gpt-4o`).
- **Maximum tokens:** the upper limit on the length of each translation (default 4096).
- **Timeout:** in seconds (default 30).

#### Ollama

- **Server URL:** the address of your Ollama server (default `http://localhost:11434`).
- **Model:** the name of an installed model, for example `llama3.1`. Required.
- **API token:** optional. A bearer token for an Ollama server behind an authenticating reverse proxy.
- **Timeout:** in seconds (default 120).

#### DeepL

- **Formality:** default, prefer formal or prefer informal, for the languages that support it.
- **English variant:** American (default) or British English.
- **Portuguese variant:** European or Brazilian Portuguese.
- **Model type:** higher quality (default) or faster.
- **Glossaries:** pick a glossary from your DeepL account for each language pair it covers.

#### Google Translate (free, unofficial)

- **No settings:** it uses Google Translate's free web endpoint, so no account or API key is needed.
- **Best-effort:** it is unofficial. Google may rate-limit or block busy servers, and translation quality is below the AI drivers. It suits trying the extension out, or small forums.
- **Formatting is verified:** Markdown structure, code, links and mentions are checked after every translation. A translation that cannot be verified is retried in smaller parts, and is never stored if it still fails.

#### Settings shared by the AI drivers

Claude, OpenAI and Ollama share an **English variant** setting (British by default) and a **German variant** setting (informal *du* by default, or Swiss Standard German).

### API keys

You can enter API keys on the settings page or set them as environment variables on the server. An environment variable takes precedence, and the admin field is hidden while one is set.

| Driver | Environment variable |
| --- | --- |
| Claude | `FOF_TRANSLATE_CLAUDE_API_KEY` |
| OpenAI | `FOF_TRANSLATE_OPENAI_API_KEY` |
| Ollama | `FOF_TRANSLATE_OLLAMA_API_KEY` (optional token) |
| DeepL | `FOF_TRANSLATE_DEEPL_API_KEY` |

The legacy `IANM_TRANSLATE_{DRIVER}_API_KEY` variables from `ianm/translate` are still honoured.

### Other settings

| Setting | Default | Description |
| --- | --- | --- |
| Allow translating into any language | On | Everyone who may translate can pick any forum language, not only their own. |
| Use native language names | Off | Show language names in their own language (Deutsch, 日本語) instead of the reader's. |
| Minimum length for language detection | 10 | Content shorter than this many characters is treated as unknown language. |
| Translations per minute | 30 | How many translations each user (or guest IP address) may request per minute. |
| Glossary | Empty | Terms that are never translated. |

### Permissions

| Permission | Default | Description |
| --- | --- | --- |
| Translate content (`fof-translate.translate`) | Guest | Translate into the reader's current forum language. |
| Translate into any forum language (`fof-translate.translateAnyLanguage`) | Moderator | Pick any forum language the driver supports. |
| Refresh cached translations (`fof-translate.refresh`) | Admin only | Force a fresh translation even when the cached one is current. Human-edited translations are never replaced. |

While **Allow translating into any language** is on (the default), everyone with *Translate content* can also translate into any forum language. Turn it off to limit that to groups holding *Translate into any forum language*.

## Privacy and auditing

### Where content goes

When someone asks for a translation, the text of that post, title or poll is sent to the driver's service: Anthropic (Claude), OpenAI, DeepL or Google. With Ollama it stays on your own server. Only the text is sent, never the reader's or the author's account details. Language detection runs on your server and sends nothing.

If you use a hosted driver, mention that service in your privacy policy.

### flarum/gdpr

With [flarum/gdpr](https://github.com/flarum/gdpr) enabled:

- **Data export** includes the translations of the user's posts and discussion titles, with the language detected for each.
- **Deletion** removes the translations of the posts, discussions and polls deleted with the user's content.
- **Anonymization** keeps the translations, because it keeps the content they translate.

### flarum/audit

With [flarum/audit](https://github.com/flarum/audit) enabled, these actions are logged. Ordinary translation requests are not.

| Action | Logged when |
| --- | --- |
| `fof_translate.post_translation_refreshed`, `fof_translate.discussion_translation_refreshed`, `fof_translate.poll_translation_refreshed` | Someone with *Refresh cached translations* forces a fresh translation. |
| `fof_translate.driver_tested` | An admin tests a driver from the settings page. The entry records whether the test worked. |
| `fof_translate.language_detection_run` | Language detection runs from the admin panel or `fof:translate:detect`. |
| `fof_translate.translations_pruned` | `fof:translate:prune` runs. A `--dry-run` is not logged. |
| `fof_translate.translations_repaired` | `fof:translate:repair-xml --fix` runs. |

flarum/audit logs changes to the driver settings as ordinary setting changes, without their values, so API keys never reach the log.

## Upgrading from ianm/translate

`fof/translate` takes over the database tables of `ianm/translate`. Your cached translations stay in place and are served without being translated again.

> **Warning**
> Remove `ianm/translate` with Composer only. Do **not** use the admin panel's uninstall/purge option for it. Purging runs its down-migrations, which delete the translation tables and every cached translation.

1. Upgrade your forum to Flarum 2.x.
2. Remove the old extension:
   ```sh
   composer remove ianm/translate
   ```
3. Install the new one:
   ```sh
   composer require fof/translate:"*"
   ```
4. Enable **FoF Translate** in the admin panel.
5. Run the migrations and clear the cache:
   ```sh
   php flarum migrate
   php flarum cache:clear
   ```
6. Check the driver on the settings page. The DeepL, OpenAI and free Google Translate drivers carry over automatically.

The migrations only copy data. They never change or delete what `ianm/translate` left behind, and running them again does nothing.

### What carries over

- Cached post, discussion title, poll and poll option translations
- Detected post and discussion languages
- Settings, including the selected driver (DeepL, OpenAI, or the free Google Translate driver), API keys, DeepL glossary mappings, the glossary, and the English and German variants
- Permission grants: *translate into own language*, *translate into all languages* and *refresh translations*

### Behaviour change: target languages

`ianm/translate` 1.x only ever offered the reader's current language, because of a bug. `fof/translate` offers every forum language the driver supports, unless your permissions or the **Allow translating into any language** setting restrict it. If you want readers limited to their own language, turn that setting off.

## Console commands

| Command | Description |
| --- | --- |
| `php flarum fof:translate:detect` | Detects the language of existing posts and discussion titles locally. Makes no API calls. |
| `php flarum fof:translate:prune` | Deletes translations into the content's own language, and poll translations whose poll no longer exists. |
| `php flarum fof:translate:repair-xml` | Finds post translations with corrupted formatting, such as those produced by the DeepL bug in `ianm/translate`, and marks them for re-translation. |

**`fof:translate:detect`**

Posts and discussion titles are detected automatically when they are saved, so this is only needed for content written before the extension was enabled (for example after upgrading from `ianm/translate`). Without options it processes both posts and discussions that have never been checked. Content too short to classify (shorter than the *short content length* setting) is assumed to be in the language its author uses the forum in, which is their chosen locale or the forum default. Content that is long enough but ambiguous, or that has no text at all, is recorded as undetermined and skipped on later runs unless `--force` is used. Polls need no backfill: their language is detected when they are displayed.

The same backfill can be run from the admin panel: **FoF Translate → Language detection** shows how much content is still unchecked and runs the detection in batches with a progress bar.

| Option | Description |
| --- | --- |
| `--posts` | Only posts |
| `--discussions` | Only discussions |
| `--force` | Re-detect content that already has a language |
| `--chunk=500` | Rows per batch |

**`fof:translate:prune`**

| Option | Description |
| --- | --- |
| `--dry-run` | Only count what would be deleted |

**`fof:translate:repair-xml`** runs as a dry run unless you pass `--fix`.

| Option | Description |
| --- | --- |
| `--fix` | Mark corrupted rows for re-translation |
| `--chunk=100` | Rows per batch |
| `--ids=` | Comma-separated `post_translations` IDs to check |
| `--provider=` | Only rows from this provider, for example `deepl` |
| `--since=` | Only rows updated on or after this date (`Y-m-d`) |

## For developers

### Adding a translation driver

Implement `FoF\Translate\Driver\TranslationDriver` and register it from your extension's `extend.php`:

```php
return [
    (new FoF\Translate\Extend\TranslationDriver())
        ->add(Acme\LibreTranslate\LibreTranslateDriver::class),
];
```

The interface:

| Method | Purpose |
| --- | --- |
| `static name(): string` | Machine name. Used as the `fof-translate.driver` setting value, the `fof-translate.{name}.*` settings keys and the `FOF_TRANSLATE_{NAME}_API_KEY` environment variable. |
| `isConfigured(): bool` | Whether all required settings are present. No network calls. |
| `translate(TranslationRequest $request): TranslationResult` | Translates `$request->text` into `$request->targetLanguage`. Return the detected source language in the result when the provider reports one. Throw `TranslationFailedException` on failure. |
| `targetLanguages(): array` | Lower-case ISO 639-1 codes the driver can translate into. The result is cached. |
| `settingsFields(): array` | Admin fields keyed by setting suffix: `['type' => 'string'\|'password'\|'url'\|'number'\|'select'\|'switch', 'required' => bool, 'options' => [...]]`. The admin page renders them automatically. No network calls. |

The text you receive has protected markup replaced by placeholders. Return every placeholder unchanged. Drivers are only resolved when a translation is requested, so constructors must not make network calls. The `FoF\Translate\Driver\Concerns\ResolvesApiKey` trait gives your driver the same environment-variable and setting lookup as the bundled drivers.

### Protecting custom markup

Use the `TranslationProtection` extender to keep your extension's BBCode away from the translator:

```php
return [
    (new FoF\Translate\Extend\TranslationProtection())
        // Keep the [callout] tags, translate the text inside them
        ->protectBBCodeSelectively('callout', 'callout')
        // Keep [code-sample]...[/code-sample] and everything inside it as-is
        ->protectBBCodeFully('code-sample', 'code-sample')
        // Keep a self-closing tag such as [product-card id=12]
        ->protectSelfClosingBBCode('product-card', 'product-card'),
];
```

For anything else, `addProtectionRule(string $name, string $pattern, string $type = 'full', ?callable $processor = null)` takes a regular expression. With type `full` the whole match is protected. With type `selective`, capture groups 1 and 3 are protected and group 2 is translated. Your rules run after the built-in ones, and registering an existing name replaces that rule.

## Links

- [![OpenCollective](https://img.shields.io/badge/donate-friendsofflarum-44AEE5?style=for-the-badge&logo=open-collective)](https://opencollective.com/fof/donate)
- [Flarum Discuss post](https://discuss.flarum.org/d/PUT_DISCUSS_SLUG_HERE)
- [Source code on GitHub](https://github.com/FriendsOfFlarum/translate)
- [Report an issue](https://github.com/FriendsOfFlarum/translate/issues)
- [Download via Packagist](https://packagist.org/packages/fof/translate)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
