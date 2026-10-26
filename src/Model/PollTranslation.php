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

use FoF\Polls\Poll;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * fof/polls is optional: the Poll class is only referenced when a relation is resolved.
 *
 * @property int         $poll_id
 * @property string      $question
 * @property string|null $subtitle
 * @property string|null $image_alt
 * @property Poll|null   $poll
 */
class PollTranslation extends AbstractTranslation
{
    protected $table = 'poll_translations';

    /**
     * Option translations in the same language, set by TranslationService for serialization.
     *
     * @var Collection<int, PollOptionTranslation>|null
     */
    public ?Collection $optionTranslations = null;

    public static function subjectKey(): string
    {
        return 'poll_id';
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }
}
