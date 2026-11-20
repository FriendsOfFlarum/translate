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

use Flarum\Foundation\ValidationException;
use Flarum\Settings\Event\Saving;
use FoF\Translate\Driver\OpenAIDriver;
use Symfony\Contracts\Translation\TranslatorInterface;

class ValidateOpenAIBaseUrl
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function handle(Saving $event): void
    {
        $key = 'fof-translate.openai.base_url';
        $value = $event->settings[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || OpenAIDriver::normalizeBaseUrl($value) === null) {
            throw new ValidationException([$key => $this->translator->trans('fof-translate.api.invalid_openai_base_url')]);
        }
    }
}
