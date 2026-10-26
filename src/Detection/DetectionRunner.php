<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Detection;

use Flarum\Post\CommentPost;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Local ELD backfill in resumable batches, shared by `fof:translate:detect` and the
 * admin detection endpoint. Reads raw columns (no models, no formatter callbacks), and
 * writes each batch with one CASE UPDATE. Never calls a translation driver.
 */
class DetectionRunner
{
    public const TYPES = ['posts', 'discussions'];

    public function __construct(
        protected ContentLanguage $language,
        protected AuthorLocale $authors,
        protected ConnectionInterface $db,
    ) {
    }

    /**
     * Process rows with id > $after in id order, until $limit rows or the time budget is spent.
     * Without $force only never-checked rows (detected_lang IS NULL) are selected; rows
     * that were checked but undetermined ('') are skipped.
     *
     * @return array{processed: int, detected: int, after: int|null, done: bool}
     *                                                                           `after` is the cursor for the next call
     */
    public function batch(string $type, bool $force = false, ?int $after = null, int $limit = 1000, float $timeBudgetSeconds = 3.0): array
    {
        $this->assertType($type);

        $limit = max(1, $limit);
        $started = microtime(true);

        $query = $this->db->table($type)
            ->when($type === 'posts', fn ($query) => $query->where('type', CommentPost::$type))
            ->when(! $force, fn ($query) => $query->whereNull('detected_lang'))
            ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->limit($limit);

        $rows = $type === 'posts'
            ? $query->get(['id', 'content', 'user_id'])
            : $query->get(['id', 'title', 'first_post_id', 'user_id']);

        // Author locales are only needed for text too short to detect: loaded once per batch, on first use.
        $authorLocales = null;
        $authorLocale = function (?int $userId) use (&$authorLocales, $rows): ?string {
            $authorLocales ??= $this->authors->forUsers($rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

            return $userId ? ($authorLocales[$userId] ?? $this->authors->fromPreference(null)) : $this->authors->fromPreference(null);
        };

        $firstPosts = $type === 'discussions' && $rows->isNotEmpty()
            ? $this->db->table('posts')->whereIn('id', $rows->pluck('first_post_id')->filter()->all())->pluck('content', 'id')
            : collect();

        $values = [];
        $cursor = $after;
        $outOfTime = false;

        foreach ($rows as $row) {
            $author = fn () => $authorLocale($row->user_id ? (int) $row->user_id : null);

            $values[(int) $row->id] = $type === 'posts'
                ? $this->language->ofPostXml($row->content, $author)
                : $this->language->ofDiscussion(
                    (string) $row->title,
                    $row->first_post_id ? PlainText::fromXml($firstPosts->get($row->first_post_id)) : null,
                    $author,
                );

            $cursor = (int) $row->id;

            if (microtime(true) - $started > $timeBudgetSeconds) {
                $outOfTime = true;
                break;
            }
        }

        $this->write($type, $values);

        return [
            'processed' => count($values),
            'detected' => count(array_filter($values, fn (string $language) => $language !== Language::UNDETERMINED)),
            'after' => $cursor,
            'done' => ! $outOfTime && $rows->count() < $limit,
        ];
    }

    /**
     * @return array{posts: array{total: int, pending: int}, discussions: array{total: int, pending: int}}
     */
    public function counts(): array
    {
        $posts = fn () => $this->db->table('posts')->where('type', CommentPost::$type);
        $discussions = fn () => $this->db->table('discussions');

        return [
            'posts' => [
                'total' => $posts()->count(),
                'pending' => $posts()->whereNull('detected_lang')->count(),
            ],
            'discussions' => [
                'total' => $discussions()->count(),
                'pending' => $discussions()->whereNull('detected_lang')->count(),
            ],
        ];
    }

    /**
     * @param array<int, string> $values id => '' or ^[a-z]{2}$ (enforced again below), so
     *                                   inlining them in the CASE is safe.
     */
    protected function write(string $type, array $values): void
    {
        if (! $values) {
            return;
        }

        $case = 'CASE id';

        foreach ($values as $id => $language) {
            $case .= ' WHEN '.(int) $id." THEN '".(Language::normalize($language) ?? '')."'";
        }

        $case .= ' END';

        $this->db->table($type)->whereIn('id', array_keys($values))->update(['detected_lang' => $this->db->raw($case)]);
    }

    protected function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown detection type [$type].");
        }
    }
}
