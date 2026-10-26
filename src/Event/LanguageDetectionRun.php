<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Event;

/**
 * Language detection was run over existing content, once per content type per run.
 */
final readonly class LanguageDetectionRun
{
    /**
     * @param string $type   posts|discussions
     * @param int    $rows   rows the run covers (admin: counted when it starts; CLI: processed)
     * @param string $source admin|cli
     */
    public function __construct(
        public string $type,
        public bool $force,
        public int $rows,
        public string $source,
    ) {
    }
}
