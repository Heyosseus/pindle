<?php

declare(strict_types=1);

namespace Pindle\Review;

use Illuminate\Database\Eloquent\Model;
use Pindle\Support\Key;

/**
 * Review summaries worked out once per request, for a whole page of records at
 * a time.
 *
 * A table column is asked for its value one row at a time, and it has no way to
 * know it is one of forty. Left alone it would run `ReviewSummary::for()` forty
 * times: a hundred and twenty queries and forty whole-file hashes to draw one
 * column. This is what the column asks instead -- the first row hands over every
 * record on the page, the batch is summarised in one go, and the other
 * thirty-nine are answered from memory.
 *
 * Bound `scoped`, so the memory lasts exactly one request. A singleton would
 * keep telling a long-running worker about a review that has since moved on.
 *
 * @internal
 */
final class ReviewCache
{
    /** @var array<string, ReviewSummary> */
    private array $summaries = [];

    /**
     * The summary for one record, filling in its neighbours while it is here.
     *
     * @param  iterable<Model>  $batch  every record on the page, the caller included
     */
    public function for(Model $record, string $documentKey = 'default', iterable $batch = []): ReviewSummary
    {
        $identity = $this->identity($record, $documentKey);

        if (isset($this->summaries[$identity])) {
            return $this->summaries[$identity];
        }

        $this->prime($this->pending($batch, $record, $documentKey), $documentKey);

        return $this->summaries[$identity] ??= ReviewSummary::for($record, $documentKey);
    }

    /**
     * The records in the batch that have not been summarised yet.
     *
     * The subject is included whether or not the batch mentioned it, so an entry
     * on a record page -- which has no batch at all -- still gets an answer.
     *
     * @param  iterable<Model>  $batch
     * @return list<Model>
     */
    private function pending(iterable $batch, Model $record, string $documentKey): array
    {
        $pending = [$this->identity($record, $documentKey) => $record];

        foreach ($batch as $candidate) {
            $identity = $this->identity($candidate, $documentKey);

            if (! isset($this->summaries[$identity])) {
                $pending[$identity] = $candidate;
            }
        }

        return array_values($pending);
    }

    /**
     * Summarise a batch, one grouped pair of queries per morph class.
     *
     * @param  list<Model>  $records
     */
    private function prime(array $records, string $documentKey): void
    {
        /** @var array<string, list<Model>> $byClass */
        $byClass = [];

        foreach ($records as $record) {
            $byClass[$record->getMorphClass()][] = $record;
        }

        foreach ($byClass as $class => $group) {
            foreach (ReviewSummary::forMany($group, $documentKey) as $key => $summary) {
                $this->summaries[$class.'#'.$key.'#'.$documentKey] = $summary;
            }
        }
    }

    private function identity(Model $record, string $documentKey): string
    {
        return $record->getMorphClass().'#'.Key::of($record).'#'.$documentKey;
    }
}
