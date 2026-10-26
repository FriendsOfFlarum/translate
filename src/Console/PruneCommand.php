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

use FoF\Translate\Detection\Language;
use FoF\Translate\Event\TranslationsPruned;
use FoF\Translate\Translation\TargetLanguages;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Deletes machine translations into the subject's own language (made before detection
 * knew better) and poll translations orphaned by deleted polls/options. Human-edited
 * rows are never pruned for their language.
 */
class PruneCommand extends Command
{
    protected $signature = 'fof:translate:prune {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete redundant same-language translations and orphaned poll translations';

    public function handle(ConnectionInterface $db, Dispatcher $events): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schema = $db->getSchemaBuilder();

        $sameLanguage = $this->pruneSameLanguage($db, $dryRun, 'post_translations', 'post_id', 'posts')
            + $this->pruneSameLanguage($db, $dryRun, 'discussion_translations', 'discussion_id', 'discussions');

        $orphanedPolls = $orphanedOptions = 0;

        if ($schema->hasTable('polls') && $schema->hasTable('poll_translations')) {
            $this->report($dryRun, 'orphaned poll translations', $orphanedPolls = $this->orphans($db, 'poll_translations', 'poll_id', 'polls'));
        }

        if ($schema->hasTable('poll_options') && $schema->hasTable('poll_option_translations')) {
            $this->report($dryRun, 'orphaned poll option translations', $orphanedOptions = $this->orphans($db, 'poll_option_translations', 'option_id', 'poll_options'));
        }

        if (! $dryRun) {
            $events->dispatch(new TranslationsPruned($sameLanguage, $orphanedPolls, $orphanedOptions));
        }

        return self::SUCCESS;
    }

    protected function pruneSameLanguage(ConnectionInterface $db, bool $dryRun, string $table, string $foreignKey, string $subjects): int
    {
        $ids = [];

        // Compared in PHP so "pt-BR" matches a detected "pt" on every database.
        $db->table($table)
            ->join($subjects, "$subjects.id", '=', "$table.$foreignKey")
            ->whereNotNull("$subjects.detected_lang")
            // Human-edited rows are authoritative, whatever their language.
            ->whereNull("$table.edited_by")
            ->select(["$table.id", "$table.language", "$subjects.detected_lang"])
            ->orderBy("$table.id")
            ->chunk(1000, function ($rows) use (&$ids) {
                foreach ($rows as $row) {
                    $source = Language::normalize($row->detected_lang);

                    if ($source !== null && TargetLanguages::primary((string) $row->language) === $source) {
                        $ids[] = (int) $row->id;
                    }
                }
            });

        if (! $dryRun) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $db->table($table)->whereIn('id', $chunk)->delete();
            }
        }

        $this->report($dryRun, "same-language rows in $table", count($ids));

        return count($ids);
    }

    protected function orphans(ConnectionInterface $db, string $table, string $foreignKey, string $parents): int
    {
        $query = fn (): Builder => $db->table($table)->whereNotIn($foreignKey, fn (Builder $q) => $q->select('id')->from($parents));

        return $this->option('dry-run') ? $query()->count() : $query()->delete();
    }

    protected function report(bool $dryRun, string $what, int $count): void
    {
        $this->info(($dryRun ? 'Would delete' : 'Deleted')." {$count} {$what}.");
    }
}
