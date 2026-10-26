<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Api;

use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use FoF\Translate\Detection\Language;

/**
 * `detectedLang` is a plain column: zero added queries on list/show endpoints. Null
 * means unknown, whether never checked (NULL) or undetermined ('').
 */
abstract class DetectedLangField
{
    public static function post(): array
    {
        return [
            Schema\Str::make('detectedLang')
                ->nullable()
                ->visible(fn (Post $post) => $post instanceof CommentPost)
                ->get(fn (Post $post) => Language::normalize($post->detected_lang)),
        ];
    }

    public static function discussion(): array
    {
        return [
            Schema\Str::make('detectedLang')
                ->nullable()
                ->get(fn (Discussion $discussion) => Language::normalize($discussion->detected_lang)),
        ];
    }
}
