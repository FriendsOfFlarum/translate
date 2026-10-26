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

use Flarum\Discussion\Event\Saving;
use Flarum\Post\CommentPost;
use FoF\Translate\Detection\AuthorLocale;
use FoF\Translate\Detection\ContentLanguage;
use FoF\Translate\Detection\PlainText;
use Illuminate\Support\Arr;

class DetectDiscussionLanguage
{
    public function __construct(
        protected ContentLanguage $language,
        protected AuthorLocale $authors,
    ) {
    }

    public function handle(Saving $event): void
    {
        $discussion = $event->discussion;

        if (! $discussion->isDirty('title')) {
            return;
        }

        $discussion->detected_lang = $this->language->ofDiscussion(
            (string) $discussion->title,
            fn () => $this->firstPostText($event),
            fn () => $this->authors->of($discussion->user),
        );
    }

    /**
     * Fallback text for short titles. On create the first post does not exist yet, so the
     * raw content from the request is used; on a rename, the stored first post.
     */
    protected function firstPostText(Saving $event): ?string
    {
        if (! $event->discussion->exists) {
            $content = Arr::get($event->data, 'attributes.content');

            return is_string($content) ? $content : null;
        }

        $first = $event->discussion->firstPost;

        return $first instanceof CommentPost ? PlainText::fromXml($first->parsed_content) : null;
    }
}
