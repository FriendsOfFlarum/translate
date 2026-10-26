<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Driver;

use Closure;
use FoF\Translate\Content\ContentProtector;
use FoF\Translate\Driver\Google\GoogleClient;
use FoF\Translate\Driver\GoogleDriver;
use FoF\Translate\Driver\TranslationFailedException;
use FoF\Translate\Driver\TranslationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

class GoogleDriverTest extends DriverTestCase
{
    /** @var list<array{string, string, string|null}> */
    protected array $requests = [];

    /**
     * @param Closure(string): string $translate
     */
    protected function scripted(Closure $translate, ?string $detected = 'en'): GoogleDriver
    {
        $this->requests = [];

        return new class(new NullLogger(), $translate, $detected, $this->requests) extends GoogleDriver {
            /** @param list<array{string, string, string|null}> $log */
            public function __construct($logger, protected Closure $fake, protected ?string $detected, protected array &$log)
            {
                parent::__construct($logger);
            }

            protected function request(string $text, string $target, ?string $source): array
            {
                $this->log[] = [$text, $target, $source];

                return [($this->fake)($text), $this->detected];
            }
        };
    }

    protected function real(Client $http): GoogleDriver
    {
        $client = (new class(options: []) extends GoogleClient {
            public function useHttp(Client $http): static
            {
                $this->client = $http;

                return $this;
            }
        })->useHttp($http);

        return new class(new NullLogger(), $client) extends GoogleDriver {
            public function __construct($logger, protected GoogleClient $fakeClient)
            {
                parent::__construct($logger);
            }

            protected function client(): GoogleClient
            {
                return $this->fakeClient->setClient('gtx');
            }
        };
    }

    #[Test]
    public function end_to_end_with_content_protector_keeps_everything_protected(): void
    {
        $source = "Run this:\n\n    php flarum cache:clear\n\n- Set `max_tokens` and snake_case_value\n- See [the docs](https://docs.flarum.org) **now**  \nthanks @\"Ian M\"#p12";
        $protector = new ContentProtector(ContentProtector::defaultRules());
        $protected = $protector->protect($source);

        $result = $this->scripted(fn (string $t) => mb_strtoupper($t))->translate(new TranslationRequest($protected->text, 'de'));
        $restored = $protector->restore($result->text, $protected);

        $this->assertSame(
            "RUN THIS:\n\n    php flarum cache:clear\n\n- SET `max_tokens` AND snake_case_value\n- SEE [THE DOCS](https://docs.flarum.org) **NOW**  \nTHANKS @\"Ian M\"#p12",
            $restored
        );
        $this->assertSame('en', $result->detectedSourceLanguage);
        $this->assertCount(1, $this->requests);
        $this->assertSame('de', $this->requests[0][1]);
    }

    #[Test]
    public function damaged_response_never_reaches_the_caller(): void
    {
        $source = "- one **two**\n- three _four_\n\nlast line";
        $driver = $this->scripted(fn (string $t) => preg_replace('/__12345_S_\d+__/', '', mb_strtoupper($t), 1) ?? $t);

        $this->assertSame(mb_strtoupper($source), $driver->translate(new TranslationRequest($source, 'fr'))->text);
    }

    #[Test]
    public function batch_joins_single_line_strings_into_one_request(): void
    {
        $driver = $this->scripted(fn (string $t) => mb_strtoupper($t));

        $results = $driver->translateBatch([
            new TranslationRequest('Favourite *colour*?', 'de', isPlainText: true),
            new TranslationRequest(' Red ', 'de', isPlainText: true),
            new TranslationRequest('42', 'de', isPlainText: true),
            new TranslationRequest('Blue', 'de', isPlainText: true),
        ]);

        $this->assertSame(['FAVOURITE *COLOUR*?', ' RED ', '42', 'BLUE'], array_map(fn ($r) => $r->text, $results));
        $this->assertCount(1, $this->requests);
        $this->assertSame(3, count(explode("\n", $this->requests[0][0])));
        $this->assertNull($results[2]->detectedSourceLanguage);
        $this->assertSame('en', $results[3]->detectedSourceLanguage);
    }

    #[Test]
    public function batch_falls_back_to_one_request_per_string_on_count_mismatch(): void
    {
        $driver = $this->scripted(fn (string $t) => str_replace("\n", ' / ', mb_strtoupper($t)));

        $results = $driver->translateBatch([
            new TranslationRequest('Yes', 'de'),
            new TranslationRequest('No', 'de'),
            new TranslationRequest("Multi\nline", 'de'),
        ]);

        $this->assertSame(['YES', 'NO', "MULTI\nLINE"], array_map(fn ($r) => $r->text, $results));
        // joined attempt, then Yes and No separately, then the multi-line string (which needs segment mode).
        $this->assertGreaterThanOrEqual(4, count($this->requests));
    }

    #[Test]
    public function rate_limit_is_reported_clearly(): void
    {
        $driver = $this->real($this->mockHttp([new Response(429, [], '<html>Sorry...</html>')]));

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('Google blocked or rate-limited this server');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function block_page_with_200_is_a_failure_not_a_translation(): void
    {
        $driver = $this->real($this->mockHttp([new Response(200, [], '<html>Sorry...</html>')]));

        $this->expectException(TranslationFailedException::class);
        $this->expectExceptionMessage('unreadable response');

        $driver->translate(new TranslationRequest('Hello', 'de'));
    }

    #[Test]
    public function parses_gtx_json_and_keeps_commas_in_sparse_responses(): void
    {
        $driver = $this->real($this->mockHttp([
            new Response(200, [], '[[["Hallo Welt","Hello world",null,null,10]],null,"en",null,null,null,null,[]]'),
            new Response(200, [], '[[["Hallo,, [,Welt","Hello,, [,world",,,10]],,"zh-CN"]'),
        ]));

        $first = $driver->translate(new TranslationRequest('Hello world', 'de'));
        $this->assertSame('Hallo Welt', $first->text);
        $this->assertSame('en', $first->detectedSourceLanguage);

        $query = $this->lastRequest()->getUri()->getQuery();
        $this->assertStringContainsString('client=gtx', $query);
        $this->assertStringContainsString('tl=de', $query);
        $this->assertStringContainsString('sl=auto', $query);

        $second = $driver->translate(new TranslationRequest('Hello,, [,world', 'de'));
        $this->assertSame('Hallo,, [,Welt', $second->text);
        $this->assertSame('zh', $second->detectedSourceLanguage);
    }

    #[Test]
    public function maps_language_codes_both_ways(): void
    {
        $driver = $this->real($this->mockHttp([
            new Response(200, [], '[[["你好","Hello",null,null,10]],null,"iw"]'),
            new Response(200, [], <<<'HTML'
                <html><head></head><body>
                <div class="language-item"><a href="./m?sl=auto&amp;hl=en">Detect language</a></div>
                <div class="language-item"><a href="./m?sl=de&amp;hl=en">German</a></div>
                <div class="language-item"><a href="./m?sl=zh-CN&amp;hl=en">Chinese (Simplified)</a></div>
                <div class="language-item"><a href="./m?sl=zh-TW&amp;hl=en">Chinese (Traditional)</a></div>
                <div class="language-item"><a href="./m?sl=iw&amp;hl=en">Hebrew</a></div>
                <div class="language-item"><a href="./m?sl=fil&amp;hl=en">Filipino</a></div>
                </body></html>
                HTML),
        ]));

        $result = $driver->translate(new TranslationRequest('Hello', 'zh', 'en'));

        $this->assertStringContainsString('tl=zh-CN', $this->lastRequest()->getUri()->getQuery());
        $this->assertStringContainsString('sl=en', $this->lastRequest()->getUri()->getQuery());
        $this->assertSame('he', $result->detectedSourceLanguage);
        $this->assertSame(['de', 'zh', 'he'], $driver->targetLanguages());
    }

    #[Test]
    public function needs_no_configuration(): void
    {
        $driver = new GoogleDriver(new NullLogger());

        $this->assertSame('google', GoogleDriver::name());
        $this->assertTrue($driver->isConfigured());
        $this->assertSame([], $driver->settingsFields());
    }
}
