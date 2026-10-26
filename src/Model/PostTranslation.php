<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Model;

use Flarum\Post\CommentPost;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int              $post_id
 * @property string|null      $content  s9e XML, parsed with the post's formatter as the post author
 * @property CommentPost|null $post     only comment posts are translatable
 */
class PostTranslation extends AbstractTranslation
{
    protected $table = 'post_translations';

    public static function subjectKey(): string
    {
        return 'post_id';
    }

    /**
     * @return BelongsTo<CommentPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommentPost::class, 'post_id');
    }
}
