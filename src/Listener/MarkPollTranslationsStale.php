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

use FoF\Polls\Events\PollOptionUpdated;
use FoF\Polls\Events\PollWasEdited;
use FoF\Translate\Model\PollOptionTranslation;
use FoF\Translate\Model\PollTranslation;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registered only when fof/polls is enabled. fof/polls dispatches its events after
 * saving, so wasChanged() tells whether translated text actually changed (an edit
 * that only moves the end date leaves translations fresh).
 */
class MarkPollTranslationsStale
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PollWasEdited::class, $this->pollEdited(...));
        $events->listen(PollOptionUpdated::class, $this->optionUpdated(...));
    }

    public function pollEdited(PollWasEdited $event): void
    {
        if (! $event->poll->wasChanged(['question', 'subtitle', 'image_alt'])) {
            return;
        }

        PollTranslation::query()
            ->where('poll_id', $event->poll->id)
            ->whereNull('edited_by')
            ->update(['update_needed' => true]);
    }

    public function optionUpdated(PollOptionUpdated $event): void
    {
        if (! $event->option->wasChanged('answer')) {
            return;
        }

        PollOptionTranslation::query()
            ->where('option_id', $event->option->id)
            ->whereNull('edited_by')
            ->update(['update_needed' => true]);
    }
}
