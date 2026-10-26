<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\fixtures;

use FoF\Translate\Driver\BatchTranslationDriver;
use FoF\Translate\Driver\TranslationRequest;

class FakeBatchDriver extends FakeDriver implements BatchTranslationDriver
{
    /** @var list<list<TranslationRequest>> */
    public static array $batches = [];

    public static function name(): string
    {
        return 'fake-batch';
    }

    public function translateBatch(array $requests): array
    {
        static::$batches[] = $requests;

        return array_map(fn (TranslationRequest $request) => $this->translate($request), $requests);
    }
}
