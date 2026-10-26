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

use Flarum\Discussion\Event\Renamed;
use Flarum\Post\Event\Revised;
use FoF\Translate\Model\DiscussionTranslation;
use FoF\Translate\Model\PostTranslation;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Lazy staleness: one UPDATE per edit; the next click re-translates. Human-edited
 * rows (edited_by set) are authoritative and keep their state.
 */
class MarkTranslationsStale
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Revised::class, $this->postRevised(...));
        $events->listen(Renamed::class, $this->discussionRenamed(...));
    }

    public function postRevised(Revised $event): void
    {
        PostTranslation::query()
            ->where('post_id', $event->post->id)
            ->whereNull('edited_by')
            ->update(['update_needed' => true]);
    }

    public function discussionRenamed(Renamed $event): void
    {
        DiscussionTranslation::query()
            ->where('discussion_id', $event->discussion->id)
            ->whereNull('edited_by')
            ->update(['update_needed' => true]);
    }
}
