<?php

declare(strict_types=1);

namespace Pindle\Review;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\PindleDocument;
use Pindle\Models\Annotation;
use Pindle\Pindle;
use Pindle\Support\Key;

/**
 * The state of one document's review, in one object.
 *
 * This is the part that makes "the annotations are in your database" worth
 * anything. A viewer that stores marks somewhere you cannot query has moved the
 * problem rather than solved it; being able to ask an invoice whether anyone is
 * still objecting to it -- in a table column, in a job, in a report -- is the
 * reason to keep them in your own tables at all.
 *
 * It is a projection and not a workflow. Pindle has no opinion about what an
 * open comment *means* for your approval process; it only tells you there is
 * one. Deciding what follows is the application's, which is why the events
 * exist.
 */
final readonly class ReviewSummary implements JsonSerializable
{
    public function __construct(
        public string $documentKey,
        public int $total,
        public int $open,
        public int $resolved,
        public int $orphaned,
        public int $comments,
        public ?CarbonInterface $lastActivityAt = null,
    ) {}

    /**
     * The review of one document of one model.
     *
     * Two queries and, when there is anything to compare against, one hash of
     * the file. A document nobody has marked is not hashed at all: there is
     * nothing for the hashes to disagree with, and reading a fifty-megabyte
     * scan to learn that is work with a known answer.
     */
    public static function for(Model $annotatable, string $key = 'default'): self
    {
        $summaries = self::forMany([$annotatable], $key);

        return $summaries[Key::of($annotatable)] ?? self::empty($key);
    }

    /**
     * The review of the same document across many models, keyed by model key.
     *
     * The reason this exists is the table column. `for()` in a loop is three
     * queries and a whole-file hash per row, so a forty-row index screen reads a
     * hundred and twenty times from the database and a hundred megabytes from
     * disk to draw forty badges -- and it does it on every page of the table.
     * This is two queries for the whole page, and it hashes only the documents
     * somebody has actually written on.
     *
     * Keyed by the model's own key, which means the collection wants to be of
     * one class -- a table of invoices, the normal case. {@see ReviewCache}
     * groups by morph class before calling, and anything mixing classes should
     * do the same.
     *
     * @param  iterable<Model>  $annotatables
     * @return array<string, self>
     */
    public static function forMany(iterable $annotatables, string $key = 'default'): array
    {
        /** @var array<string, Model> $records */
        $records = [];

        foreach ($annotatables as $annotatable) {
            $records[self::identity($annotatable)] = $annotatable;
        }

        if ($records === []) {
            return [];
        }

        $tallies = self::tallies($records, $key);
        $comments = self::commentCounts($records, $key);

        $summaries = [];

        foreach ($records as $identity => $record) {
            $summaries[Key::of($record)] = self::assemble(
                $record,
                $key,
                $tallies[$identity] ?? [],
                $comments[$identity] ?? 0,
            );
        }

        return $summaries;
    }

    /**
     * Turn one record's grouped rows into a summary.
     *
     * @param  list<array{hash: string, total: int, open: int, last: string|null}>  $rows
     */
    private static function assemble(Model $record, string $key, array $rows, int $comments): self
    {
        if ($rows === []) {
            return self::empty($key);
        }

        $total = 0;
        $open = 0;
        $last = null;

        foreach ($rows as $row) {
            $total += $row['total'];
            $open += $row['open'];

            if ($row['last'] !== null && ($last === null || $row['last'] > $last)) {
                $last = $row['last'];
            }
        }

        return new self(
            documentKey: $key,
            total: $total,
            open: $open,
            resolved: $total - $open,
            orphaned: self::orphaned($record, $key, $rows),
            comments: $comments,
            lastActivityAt: $last === null ? null : Carbon::parse($last),
        );
    }

    /**
     * How many of this record's annotations were drawn on a document that is no
     * longer the one on disk.
     *
     * The hash is read here and nowhere earlier, because reaching this line at
     * all means there is at least one annotation for it to disagree with.
     *
     * @param  list<array{hash: string, total: int, open: int, last: string|null}>  $rows
     */
    private static function orphaned(Model $record, string $key, array $rows): int
    {
        $document = app(DocumentResolver::class)->resolve($record, $key);

        // A missing document cannot orphan anything. There is no replacement to
        // compare against -- only an absence, which is the application's news to
        // break and not Pindle's.
        if (! $document instanceof PindleDocument || ! $document->exists()) {
            return 0;
        }

        $hash = $document->hash();
        $orphaned = 0;

        foreach ($rows as $row) {
            if (! hash_equals($hash, $row['hash'])) {
                $orphaned += $row['total'];
            }
        }

        return $orphaned;
    }

    /**
     * Counts for every record at once, grouped down to the document hash.
     *
     * Grouping by the hash rather than comparing against a bound one is what
     * makes a single query serve records whose documents all differ: the
     * comparison happens in PHP, once per record, against a hash that is only
     * read when there is something to compare it to.
     *
     * @param  array<string, Model>  $records
     * @return array<string, list<array{hash: string, total: int, open: int, last: string|null}>>
     */
    private static function tallies(array $records, string $key): array
    {
        $rows = self::constrain(Pindle::query(), $records, $key)
            ->groupBy('annotatable_type', 'annotatable_id', 'document_hash')
            ->selectRaw('annotatable_type, annotatable_id, document_hash')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when resolved_at is null then 1 else 0 end) as open')
            ->selectRaw('max(updated_at) as last_activity')
            ->get();

        $tallies = [];

        foreach ($rows as $row) {
            $identity = self::stringify($row->getAttribute('annotatable_type'))
                .'#'.self::stringify($row->getAttribute('annotatable_id'));

            $last = $row->getAttribute('last_activity');

            $tallies[$identity][] = [
                'hash' => self::stringify($row->getAttribute('document_hash')),
                'total' => self::number($row->getAttribute('total')),
                'open' => self::number($row->getAttribute('open')),
                'last' => is_string($last) && $last !== '' ? $last : null,
            ];
        }

        return $tallies;
    }

    /**
     * How many comments hang off each record's annotations.
     *
     * @param  array<string, Model>  $records
     * @return array<string, int>
     */
    private static function commentCounts(array $records, string $key): array
    {
        $annotations = (new (Pindle::annotationModel()))->getTable();
        $comments = (new (Pindle::commentModel()))->getTable();

        // The join is only there to group by the owning model; which annotations
        // count is decided by the subquery, so soft deletes on the annotation
        // and whatever the application layered on with `Pindle::scopeUsing()`
        // are honoured here exactly as they are everywhere else. The comments'
        // own soft deletes are excluded by hand, because dropping to the query
        // builder to do the join leaves their global scope behind.
        $rows = DB::table($comments)
            ->join($annotations, $annotations.'.id', '=', $comments.'.annotation_id')
            ->whereNull($comments.'.deleted_at')
            ->whereIn(
                $comments.'.annotation_id',
                self::constrain(Pindle::query(), $records, $key)->select('id')->toBase(),
            )
            ->groupBy($annotations.'.annotatable_type', $annotations.'.annotatable_id')
            ->select([
                $annotations.'.annotatable_type as owner_type',
                $annotations.'.annotatable_id as owner_id',
            ])
            ->selectRaw('count(*) as tally')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $identity = self::stringify($row->owner_type ?? null).'#'.self::stringify($row->owner_id ?? null);

            $counts[$identity] = self::number($row->tally ?? null);
        }

        return $counts;
    }

    /**
     * Narrow a query to exactly these records' annotations on this document.
     *
     * The records are grouped by morph class first, so a mixed collection costs
     * one `whereIn` per class rather than one `orWhere` per row -- a page of
     * fifty invoices produces a two-clause predicate, not fifty.
     *
     * @param  Builder<Annotation>  $query
     * @param  array<string, Model>  $records
     * @return Builder<Annotation>
     */
    private static function constrain(Builder $query, array $records, string $key): Builder
    {
        /** @var array<string, list<string>> $byType */
        $byType = [];

        foreach ($records as $record) {
            $byType[$record->getMorphClass()][] = Key::of($record);
        }

        return $query
            ->where('document_key', $key)
            ->where(static function (Builder $outer) use ($byType): void {
                foreach ($byType as $type => $ids) {
                    $outer->orWhere(static function (Builder $inner) use ($type, $ids): void {
                        $inner->where('annotatable_type', $type)->whereIn('annotatable_id', $ids);
                    });
                }
            });
    }

    /** A record's identity in the grouped rows: morph class and key. */
    private static function identity(Model $record): string
    {
        return $record->getMorphClass().'#'.Key::of($record);
    }

    private static function empty(string $key): self
    {
        return new self($key, 0, 0, 0, 0, 0);
    }

    /**
     * The drivers disagree about whether an aggregate comes back as an int or a
     * string, and a `sum()` over no rows is null rather than zero.
     */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringify(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /** Nothing outstanding and nothing pointing at the wrong place. */
    public function isSettled(): bool
    {
        return $this->open === 0 && $this->orphaned === 0;
    }

    /**
     * Whether somebody needs to look at this.
     *
     * An orphan counts even when it is resolved: a settled objection on a
     * document that has since been replaced is a settled objection to text that
     * may no longer be there, and somebody should say so out loud.
     */
    public function needsAttention(): bool
    {
        return ! $this->isSettled();
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * A short line for a table cell or a badge.
     */
    public function label(): string
    {
        if ($this->isEmpty()) {
            return 'No marks';
        }

        $parts = [];

        if ($this->open > 0) {
            $parts[] = $this->open.' open';
        }

        if ($this->resolved > 0) {
            $parts[] = $this->resolved.' resolved';
        }

        if ($this->orphaned > 0) {
            $parts[] = $this->orphaned.' orphaned';
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_key' => $this->documentKey,
            'total' => $this->total,
            'open' => $this->open,
            'resolved' => $this->resolved,
            'orphaned' => $this->orphaned,
            'comments' => $this->comments,
            'settled' => $this->isSettled(),
            'last_activity_at' => $this->lastActivityAt?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
