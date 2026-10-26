<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */
namespace FoF\Translate\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class GlobalPolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function can(User $actor, string $ability): ?string
    {
        // translate-all-enabled (default on, as in 1.x) extends "any language" to everyone who may translate.
        if ($ability === 'fof-translate.translateAnyLanguage'
            && (bool) $this->settings->get('fof-translate.translate-all-enabled', true)
            && $actor->hasPermission('fof-translate.translate')) {
            return $this->allow();
        }

        return null;
    }
}
