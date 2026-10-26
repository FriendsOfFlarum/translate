<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver;

use Flarum\Formatter\Formatter;
use Flarum\Settings\OverrideSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\Translate\Content\ContentProtector;
use FoF\Translate\Translation\Glossary;
use FoF\Translate\Translation\TargetLanguages;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Admin "test" for a driver configuration: translates a built-in sample through the
 * real pipeline (protect, translate, restore, parse, render) using the admin's unsaved
 * form values as a request-only overlay on the stored settings. Nothing is persisted or
 * cached, no translation row is written and DriverManager's status cache is untouched.
 */
class DriverTester
{
    /**
     * Each sample carries the markup the pipeline must preserve. The code span and the
     * URL are identical across samples, so markupPreserved can check for them verbatim.
     */
    public const SAMPLES = [
        'de' => 'Dies ist ein **kurzer Test** des Übersetzungsdienstes. Führe `php flarum cache:clear` aus und lies die [Dokumentation](https://flarum.org), um mehr zu erfahren.',
        'en' => 'This is a **short test** of the translation service. Run `php flarum cache:clear` and read the [documentation](https://flarum.org) to learn more.',
        'fr' => 'Ceci est un **court test** du service de traduction. Exécutez `php flarum cache:clear` et lisez la [documentation](https://flarum.org) pour en savoir plus.',
        'es' => 'Esta es una **breve prueba** del servicio de traducción. Ejecuta `php flarum cache:clear` y lee la [documentación](https://flarum.org) para saber más.',
    ];

    public const SAMPLE_CODE = 'php flarum cache:clear';
    public const SAMPLE_URL = 'https://flarum.org';

    public function __construct(
        protected Container $container,
        protected DriverManager $drivers,
        protected SettingsRepositoryInterface $settings,
        protected ContentProtector $protector,
        protected Glossary $glossary,
        protected Formatter $formatter,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides unsaved form values; only `fof-translate.{name}.*`
     *                                        and `fof-translate.ai.*` keys are applied
     *
     * @return array{ok: bool, driver: string, durationMs: int, source: array{language: string, text: string},
     *               target: array{language: string}, translation: array{text: string, html: string}|null,
     *               detectedSourceLanguage: string|null, markupPreserved: bool|null, error: string|null}
     */
    public function test(string $name, array $overrides, string $locale, User $actor, ?ServerRequestInterface $request = null): array
    {
        $overlay = new OverrideSettingsRepository($this->settings, $this->filterOverrides($name, $overrides));

        $target = TargetLanguages::primary($locale);
        [$sourceLanguage, $sample] = $this->sample($target);

        $result = [
            'ok' => false,
            'driver' => $name,
            'durationMs' => 0,
            'source' => ['language' => $sourceLanguage, 'text' => $sample],
            'target' => ['language' => $target],
            'translation' => null,
            'detectedSourceLanguage' => null,
            'markupPreserved' => null,
            'error' => null,
        ];

        $started = microtime(true);

        try {
            $driver = $this->makeDriver($this->drivers->registered()[$name], $overlay);

            if (! $driver->isConfigured()) {
                $result['error'] = 'Missing required settings: '.(implode(', ', $this->missingRequired($driver, $name, $overlay)) ?: 'unknown');

                return $result;
            }

            $glossary = $this->glossary->terms();
            $protected = $this->protector->protect($sample, $glossary);

            $translated = $driver->translate(new TranslationRequest(
                text: $protected->text,
                targetLanguage: $target,
                sourceLanguage: $sourceLanguage,
                glossary: $glossary,
                isPlainText: false,
            ));

            $text = $this->protector->restore($translated->text, $protected);
        } catch (Throwable $e) {
            $result['durationMs'] = $this->elapsed($started);
            $result['error'] = $this->redact($e->getMessage(), $name, $overlay);

            $this->logger->info("[fof/translate] driver test for $name failed: ".$result['error']);

            return $result;
        }

        $result['durationMs'] = $this->elapsed($started);
        $result['ok'] = true;
        $result['translation'] = ['text' => $text, 'html' => $this->render($text, $actor, $request)];
        $result['detectedSourceLanguage'] = $translated->detectedSourceLanguage;
        $result['markupPreserved'] = str_contains($text, self::SAMPLE_CODE) && str_contains($text, self::SAMPLE_URL);

        return $result;
    }

    /**
     * @return array{0: string, 1: string} the first sample not already in the target language
     */
    public function sample(string $target): array
    {
        foreach (self::SAMPLES as $language => $text) {
            if ($language !== $target) {
                return [$language, $text];
            }
        }

        return ['en', self::SAMPLES['en']];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, string>
     */
    public function filterOverrides(string $name, array $overrides): array
    {
        $filtered = [];

        foreach ($overrides as $key => $value) {
            $key = (string) $key;

            if (! str_starts_with($key, "fof-translate.$name.") && ! str_starts_with($key, 'fof-translate.ai.')) {
                continue;
            }

            if ($value === null || is_scalar($value)) {
                $filtered[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $filtered;
    }

    /**
     * Construct the driver with the overlay wherever it takes settings, including the
     * shared AI prompt builder (so `fof-translate.ai.*` variants apply), whatever the
     * constructor parameters are called.
     *
     * @param class-string<TranslationDriver> $class
     */
    protected function makeDriver(string $class, SettingsRepositoryInterface $overlay): TranslationDriver
    {
        $parameters = [];
        $constructor = (new ReflectionClass($class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $type = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($type === SettingsRepositoryInterface::class) {
                $parameters[$parameter->getName()] = $overlay;
            } elseif ($type === AiPromptBuilder::class) {
                $parameters[$parameter->getName()] = $this->container->make(AiPromptBuilder::class, ['settings' => $overlay]);
            }
        }

        return $this->container->make($class, $parameters);
    }

    /**
     * @return list<string>
     */
    protected function missingRequired(TranslationDriver $driver, string $name, SettingsRepositoryInterface $settings): array
    {
        $missing = [];

        foreach ($driver->settingsFields() as $key => $field) {
            if (! ($field['required'] ?? false) || ($field['envOverride'] ?? false)) {
                continue;
            }

            $value = $settings->get("fof-translate.$name.$key");

            if ($value === null || trim((string) $value) === '') {
                $missing[] = (string) $key;
            }
        }

        return $missing;
    }

    /**
     * Drivers keep keys out of their messages; this is a second line of defence.
     */
    protected function redact(string $message, string $name, SettingsRepositoryInterface $settings): string
    {
        $secrets = [];

        foreach ($settings->all() as $key => $value) {
            if (str_starts_with((string) $key, "fof-translate.$name.") && preg_match('/(api_key|token|secret|password)$/i', (string) $key)) {
                $secrets[] = $value;
            }
        }

        $env = strtoupper($name).'_API_KEY';

        foreach (["FOF_TRANSLATE_$env", "IANM_TRANSLATE_$env"] as $variable) {
            $secrets[] = $_ENV[$variable] ?? $_SERVER[$variable] ?? getenv($variable);
        }

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen(trim($secret)) >= 4) {
                $message = str_replace(trim($secret), '[redacted]', $message);
            }
        }

        return $message;
    }

    /**
     * Driver output is untrusted: only formatter output (parsed, then rendered) is returned as HTML.
     */
    protected function render(string $text, User $actor, ?ServerRequestInterface $request): string
    {
        try {
            return $this->formatter->render($this->formatter->parse($text, null, $actor), null, $request);
        } catch (Throwable $e) {
            $this->logger->info('[fof/translate] driver test could not render the sample: '.$e->getMessage());

            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }
    }

    protected function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
