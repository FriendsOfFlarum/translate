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

use Flarum\Formatter\Formatter;
use Flarum\Post\CommentPost;
use FoF\Translate\Content\MentionRelations;
use FoF\Translate\Event\TranslationsRepaired;
use FoF\Translate\Model\PostTranslation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Ported from ianm/translate #103: finds post_translations whose s9e XML was corrupted
 * by DeepL entity handling and marks them update_needed, so the next click re-translates.
 */
class RepairXmlCommand extends Command
{
    protected $signature = 'fof:translate:repair-xml
        {--fix : Mark corrupted rows for re-translation (default is a dry run)}
        {--chunk=100 : Rows per batch}
        {--ids= : Comma-separated post_translations ids to check}
        {--provider= : Only rows from this provider (e.g. deepl)}
        {--since= : Only rows updated on or after this date (Y-m-d)}';

    protected $description = 'Detect post translations with invalid s9e XML and mark them for re-translation';

    public function handle(Formatter $formatter, Dispatcher $events): int
    {
        $dryRun = ! $this->option('fix');

        if ($dryRun) {
            $this->info('DRY RUN: no rows will be modified. Pass --fix to apply changes.');
        }

        $query = $this->buildQuery();
        $total = (clone $query)->count();

        $this->info("Scanning {$total} post_translations rows...");

        $scanned = $corrupted = $skipped = 0;
        $ids = [];

        $query->with(['post', ...MentionRelations::paths('post.')])->chunkById(max(1, (int) $this->option('chunk')), function (Collection $rows) use ($formatter, $dryRun, &$scanned, &$corrupted, &$skipped, &$ids) {
            /** @var PostTranslation $translation */
            foreach ($rows as $translation) {
                $scanned++;

                // Human-edited rows are authoritative, even when they look corrupt.
                if ($translation->isEdited()) {
                    $skipped++;
                    continue;
                }

                $post = $translation->post instanceof CommentPost ? $translation->post : null;

                if (! $this->isCorrupted((string) $translation->content, $formatter, $post)) {
                    continue;
                }

                $corrupted++;
                $ids[] = $translation->id;

                $this->line(sprintf(
                    '  [%s] id=%d post_id=%d lang=%s provider=%s',
                    $dryRun ? 'WOULD FIX' : 'FIXING',
                    $translation->id,
                    $translation->post_id,
                    $translation->language,
                    $translation->provider
                ));
            }
        });

        if (! $dryRun) {
            foreach (array_chunk($ids, 500) as $chunk) {
                PostTranslation::query()->whereIn('id', $chunk)->update(['update_needed' => true]);
            }

            $events->dispatch(new TranslationsRepaired(count($ids)));
        }

        $this->newLine();
        $this->info("Scanned: {$scanned}");
        $this->info("Skipped (edited by a human): {$skipped}");

        if ($corrupted === 0) {
            $this->info('No corrupted rows found.');
        } elseif ($dryRun) {
            $this->warn("Corrupted: {$corrupted}. Run with --fix to mark them for re-translation.");
        } else {
            $this->info("Corrupted: {$corrupted}. Marked update_needed; they re-translate on the next click.");
        }

        return self::SUCCESS;
    }

    protected function isCorrupted(string $content, Formatter $formatter, ?CommentPost $post): bool
    {
        if ($content === '') {
            return false;
        }

        if ($this->hasBareEntities($content)) {
            return true;
        }

        // s9e's renderer loads the XML with libxml and throws on malformed input.
        try {
            $formatter->render($content, $post);

            return false;
        } catch (InvalidArgumentException) {
            return true;
        } catch (Throwable) {
            // Not XML corruption (e.g. a missing relation): leave the row alone.
            return false;
        }
    }

    /**
     * The two DeepL corruption patterns: a bare `&` that is not an entity reference, and
     * a bare `<` that does not open a tag, comment, CDATA or processing instruction.
     */
    protected function hasBareEntities(string $xml): bool
    {
        return (bool) preg_match('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#x[0-9a-fA-F]+);)/', $xml)
            || (bool) preg_match('/<(?![a-zA-Z_\/!?])/', $xml);
    }

    /**
     * @return Builder<PostTranslation>
     */
    protected function buildQuery(): Builder
    {
        $query = PostTranslation::query();

        if ($ids = $this->option('ids')) {
            $query->whereIn('id', array_map('intval', explode(',', (string) $ids)));
        }

        if ($provider = $this->option('provider')) {
            $query->where('provider', $provider);
        }

        if ($since = $this->option('since')) {
            $query->where('updated_at', '>=', $since);
        }

        return $query;
    }
}
