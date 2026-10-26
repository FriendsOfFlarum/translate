<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Console;

use FoF\Translate\Detection\DetectionRunner;
use FoF\Translate\Event\LanguageDetectionRun;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Command;

/**
 * Local ELD backfill through DetectionRunner (the admin "detect" button uses the same
 * batches). No provider calls.
 */
class DetectLanguagesCommand extends Command
{
    protected $signature = 'fof:translate:detect
        {--posts : Only posts}
        {--discussions : Only discussions}
        {--force : Re-detect rows that were already checked}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Detect the language of existing posts and discussions locally, with no provider calls (polls are detected on the fly)';

    public function handle(DetectionRunner $runner, Dispatcher $events): int
    {
        $types = array_values(array_filter([
            $this->option('posts') ? 'posts' : null,
            $this->option('discussions') ? 'discussions' : null,
        ])) ?: DetectionRunner::TYPES;

        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));
        $counts = $runner->counts();

        foreach ($types as $type) {
            $total = $force ? $counts[$type]['total'] : $counts[$type]['pending'];

            $this->info(ucfirst($type).": {$total} to check");

            $bar = $this->output->createProgressBar($total);
            $processed = $detected = 0;
            $after = null;

            do {
                $result = $runner->batch($type, $force, $after, $chunk, PHP_FLOAT_MAX);

                $processed += $result['processed'];
                $detected += $result['detected'];
                $after = $result['after'];

                $bar->advance($result['processed']);
            } while (! $result['done']);

            $bar->finish();
            $this->newLine();

            $events->dispatch(new LanguageDetectionRun($type, $force, $processed, 'cli'));

            $this->info(sprintf(
                '%s: %d checked, %d detected, %d undetermined (ambiguous, or no text).',
                ucfirst($type),
                $processed,
                $detected,
                $processed - $detected
            ));
        }

        return self::SUCCESS;
    }
}
