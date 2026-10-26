<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */
namespace FoF\Translate\Listener;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use FoF\Translate\Detection\AuthorLocale;
use FoF\Translate\Detection\ContentLanguage;

/**
 * Local detection only: no driver, no network, no queue. Text too short to classify
 * takes the author's forum locale; long but ambiguous text stores '' so the backfill
 * does not pick it up again.
 */
class DetectPostLanguage
{
    public function __construct(
        protected ContentLanguage $language,
        protected AuthorLocale $authors,
    ) {
    }

    public function handle(Saving $event): void
    {
        $post = $event->post;

        if (! $post instanceof CommentPost) {
            return;
        }

        // New or edited content only; fof:translate:detect backfills older posts.
        if ($post->exists && ! $post->isDirty('content')) {
            return;
        }

        // The stored XML, not $post->content: unparsing runs formatter callbacks that may query.
        // The author's, not the actor's: a moderator editing someone's post is not its author.
        $post->detected_lang = $this->language->ofPostXml($post->parsed_content, fn () => $this->authors->of($post->user));
    }
}
