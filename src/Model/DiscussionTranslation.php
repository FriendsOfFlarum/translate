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

use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $discussion_id
 * @property string          $translation  the translated title (plain text)
 * @property Discussion|null $discussion
 */
class DiscussionTranslation extends AbstractTranslation
{
    protected $table = 'discussion_translations';

    public static function subjectKey(): string
    {
        return 'discussion_id';
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }
}
