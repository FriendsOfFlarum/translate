<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Audit;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use FoF\Polls\Poll;
use FoF\Translate\Event\TranslationRefreshed;

/**
 * flarum/audit payloads for a refresh, one action per subject type so the audit browser
 * can label each. A null payload skips the entry. Post and poll entries carry their
 * discussion's id, which puts them in that discussion's audit log too.
 */
class RefreshPayload
{
    /** @return array<string, int|string>|null */
    public static function post(TranslationRefreshed $event): ?array
    {
        $post = $event->subject;

        return $post instanceof CommentPost
            ? ['post_id' => $post->id, 'discussion_id' => $post->discussion_id, 'locale' => $event->locale]
            : null;
    }

    /** @return array<string, int|string>|null */
    public static function discussion(TranslationRefreshed $event): ?array
    {
        $discussion = $event->subject;

        return $discussion instanceof Discussion
            ? ['discussion_id' => $discussion->id, 'locale' => $event->locale]
            : null;
    }

    /** @return array<string, int|string>|null */
    public static function poll(TranslationRefreshed $event): ?array
    {
        $poll = $event->subject;

        if (! $poll instanceof Poll) {
            return null;
        }

        // A global poll has no post.
        return array_filter([
            'poll_id' => $poll->id,
            'post_id' => $poll->post_id,
            'discussion_id' => $poll->post?->discussion_id,
            'locale' => $event->locale,
        ], fn ($value) => $value !== null);
    }
}
