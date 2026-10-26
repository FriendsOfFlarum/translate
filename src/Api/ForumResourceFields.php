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

use Flarum\Api\Context;
use Flarum\Api\Schema;
use FoF\Translate\Translation\TargetLanguages;

/**
 * Actor-level flags. The forum resource is serialized once per request, so each is
 * computed once; target languages come from the cache, never from the provider.
 */
class ForumResourceFields
{
    public function __construct(
        protected TargetLanguages $targets,
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('fof-translate.canTranslate')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('fof-translate.translate')),
            Schema\Arr::make('fof-translate.targetLanguages')
                ->get(fn ($forum, Context $context) => $this->targets->forActor($context->getActor(), $context->request->getAttribute('locale'))),
            Schema\Boolean::make('fof-translate.canRefresh')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('fof-translate.refresh')),
        ];
    }
}
