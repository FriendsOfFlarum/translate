<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Content;

use Flarum\Post\Post;

/**
 * flarum/mentions' render and unparse callbacks read the mentioned models from the post's
 * relations when they are loaded, and otherwise query them for each post. Rendering many
 * posts' XML therefore eager-loads them first: the same relations core's post endpoints
 * load, for whichever of them are registered (flarum/mentions, plus flarum/tags for tag
 * mentions).
 */
class MentionRelations
{
    /** Relation => the paths that load it with what its callbacks read. */
    protected const RELATIONS = [
        'mentionsUsers' => ['mentionsUsers'],
        'mentionsPosts' => ['mentionsPosts.user', 'mentionsPosts.discussion'],
        'mentionsGroups' => ['mentionsGroups'],
        'mentionsTags' => ['mentionsTags'],
    ];

    /**
     * Eager-load paths for a post, or for the post behind `$prefix` (e.g. "post.").
     *
     * @return list<string>
     */
    public static function paths(string $prefix = ''): array
    {
        $post = new Post();
        $paths = [];

        foreach (self::RELATIONS as $relation => $relationPaths) {
            if ($post->isRelation($relation)) {
                foreach ($relationPaths as $path) {
                    $paths[] = $prefix.$path;
                }
            }
        }

        return $paths;
    }
}
