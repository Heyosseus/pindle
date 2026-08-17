<?php

declare(strict_types=1);

namespace Pindle\Review;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\PindleDocument;
use Pindle\Pindle;

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
     * Three queries, whatever the number of annotations: the counts, the
     * comment total, and the last time anything moved. The document is hashed
     * once, not once per annotation.
     */
    public static function for(Model $annotatable, string $key = 'default'): self
    {
        $document = app(DocumentResolver::class)->resolve($annotatable, $key);

        // A missing document cannot orphan anything -- there is nothing for the
        // hashes to disagree with.
        $hash = $document instanceof PindleDocument && $document->exists() ? $document->hash() : null;

        $counts = Pindle::query()
            ->forDocument($annotatable, $key)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when resolved_at is null then 1 else 0 end) as open')
            ->selectRaw('sum(case when document_hash <> ? then 1 else 0 end) as orphaned', [$hash ?? ''])
            ->selectRaw('max(updated_at) as last_activity')
            ->first();

        $total = self::tally($counts, 'total');
        $open = self::tally($counts, 'open');
        $orphaned = $hash === null ? 0 : self::tally($counts, 'orphaned');

        $comments = Pindle::commentModel()::query()
            ->whereIn('annotation_id', Pindle::query()->forDocument($annotatable, $key)->select('id'))
            ->count();

        $last = $counts?->getAttribute('last_activity');

        return new self(
            documentKey: $key,
            total: $total,
            open: $open,
            resolved: $total - $open,
            orphaned: $orphaned,
            comments: $comments,
            lastActivityAt: is_string($last) && $last !== '' ? Carbon::parse($last) : null,
        );
    }

    /**
     * One aggregate off the counts row.
     *
     * A `sum()` over no rows is null rather than zero, and the drivers disagree
     * about whether a count comes back as an int or a string, so the reading is
     * done in one place instead of four.
     */
    private static function tally(?Model $row, string $column): int
    {
        $value = $row?->getAttribute($column);

        return is_numeric($value) ? (int) $value : 0;
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
