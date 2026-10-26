<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\integration;

use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Locale\LocaleManager;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase as BaseTestCase;
use Flarum\User\User;
use FoF\Translate\Extend\TranslationDriver;
use FoF\Translate\Model\PostTranslation;
use FoF\Translate\Tests\fixtures\FakeBatchDriver;
use FoF\Translate\Tests\fixtures\FakeDriver;
use FoF\Translate\Tests\fixtures\SettingsFakeDriver;
use Illuminate\Contracts\Container\Container;

abstract class TestCase extends BaseTestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::$requests = [];
        FakeDriver::$detected = null;
        FakeDriver::$fail = false;
        FakeDriver::$echo = false;
        FakeDriver::$constructed = 0;
        FakeDriver::$targetCalls = 0;
        FakeDriver::$targetsFail = false;
        FakeBatchDriver::$batches = [];
        SettingsFakeDriver::$calls = [];

        $this->extension('fof-translate');

        $this->extend(
            (new TranslationDriver())->add(FakeDriver::class)->add(FakeBatchDriver::class)->add(SettingsFakeDriver::class),
            // Guest write requests would otherwise stop at CSRF (400) before the endpoint's own gate (FND-028).
            (new Extend\Csrf())
                ->exemptRoute('posts.translate')
                ->exemptRoute('discussions.translate')
                ->exemptRoute('discussions.translations')
                ->exemptRoute('polls.translate'),
            // The test site only ships English; LocaleManager is already resolved when
            // extenders run, so extend() applies to the live instance (FND-132).
            new class implements ExtenderInterface {
                public function extend(Container $container, ?Extension $extension = null): void
                {
                    $container->extend(LocaleManager::class, function (LocaleManager $locales) {
                        $locales->addLocale('de', 'Deutsch');
                        $locales->addLocale('fr', 'Français');

                        return $locales;
                    });
                }
            },
        );

        $this->setting('fof-translate.driver', 'fake');
    }

    protected function seedForum(): void
    {
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'author', 'email' => 'author@machine.local'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Ein deutscher Titel', 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2, 'detected_lang' => 'de'],
                ['id' => 2, 'title' => 'Private', 'user_id' => 3, 'first_post_id' => 3, 'is_private' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 3, 'content' => '<t><p>Das ist der erste Beitrag in dieser Diskussion.</p></t>', 'detected_lang' => 'de'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 3, 'content' => '<t><p>Hallo zusammen, wie geht es euch heute?</p></t>', 'detected_lang' => 'de'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'user_id' => 3, 'content' => '<t><p>Secret</p></t>', 'is_private' => 1],
                ['id' => 4, 'discussion_id' => 1, 'number' => 3, 'user_id' => 3, 'type' => 'discussionRenamed', 'content' => '["a","b"]'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function translate(string $type, int $id, array $attributes, ?int $as = 2): \Psr\Http\Message\ResponseInterface
    {
        $options = ['json' => ['data' => ['attributes' => $attributes]]];

        if ($as !== null) {
            $options['authenticatedAs'] = $as;
        }

        return $this->send($this->request('POST', "/api/$type/$id/translate", $options));
    }

    /**
     * Comment posts in discussion 1 by `$authorId`, each mentioning its own user (same id
     * as the post) and post 1, with a `$language` translation that keeps the mentions.
     * Rendering or unparsing them without the mention relations loaded costs queries per
     * post. Requires flarum-mentions.
     *
     * @param list<int> $ids
     * @return array<string, list<array<string, mixed>>> for prepareDatabase()
     */
    protected function mentioningPosts(array $ids, int $authorId = 3, string $language = 'en'): array
    {
        $fixtures = [User::class => [], Post::class => [], PostTranslation::class => [], 'post_mentions_user' => [], 'post_mentions_post' => []];

        foreach ($ids as $id) {
            $mentions = '<USERMENTION displayname="member'.$id.'" id="'.$id.'">@"member'.$id.'"#'.$id.'</USERMENTION> '
                .'<POSTMENTION displayname="author" id="1" number="1" discussionid="1">@"author"#p1</POSTMENTION>';

            $fixtures[User::class][] = ['id' => $id, 'username' => "member$id", 'email' => "member$id@machine.local"];
            $fixtures[Post::class][] = ['id' => $id, 'discussion_id' => 1, 'number' => $id, 'user_id' => $authorId, 'content' => "<r><p>$mentions hallo</p></r>", 'detected_lang' => 'de'];
            $fixtures[PostTranslation::class][] = ['id' => $id, 'post_id' => $id, 'language' => $language, 'content' => "<r><p>$mentions hello</p></r>"];
            $fixtures['post_mentions_user'][] = ['post_id' => $id, 'mentions_user_id' => $id];
            $fixtures['post_mentions_post'][] = ['post_id' => $id, 'mentions_post_id' => 1];
        }

        return $fixtures;
    }

    /**
     * Number of queries `$callback` runs.
     */
    protected function queryCount(callable $callback): int
    {
        $count = 0;
        $this->database()->listen(function () use (&$count) {
            $count++;
        });

        $callback();

        return $count;
    }

    protected function body(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
