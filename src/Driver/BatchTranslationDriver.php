<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Driver;

/**
 * Optional capability: translate several short strings (a poll's question,
 * subtitle and answers) in one provider call instead of one call each.
 * TranslationService falls back to sequential translate() calls for drivers
 * that do not implement it.
 */
interface BatchTranslationDriver extends TranslationDriver
{
    /**
     * All requests share the same targetLanguage and sourceLanguage.
     *
     * @param list<TranslationRequest> $requests
     * @return list<TranslationResult> one result per request, in the same order
     *
     * @throws TranslationFailedException when the call fails or the result count does not match
     */
    public function translateBatch(array $requests): array;
}
