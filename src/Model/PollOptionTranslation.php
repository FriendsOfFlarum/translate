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

use FoF\Polls\PollOption;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $option_id
 * @property string          $answer
 * @property PollOption|null $option
 */
class PollOptionTranslation extends AbstractTranslation
{
    protected $table = 'poll_option_translations';

    public static function subjectKey(): string
    {
        return 'option_id';
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
