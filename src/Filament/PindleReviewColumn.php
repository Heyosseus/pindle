<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * ```php
 * PindleReviewColumn::make('review')->documentKey('default')
 * ```
 *
 * A badge on the index screen saying how much is still open on each record's
 * document, and how much no longer points at the right place.
 *
 * This is the column that makes the package worth installing rather than
 * admiring. A viewer you have to open to discover there is nothing in it is a
 * viewer nobody opens; a table that says "3 open" against two rows out of forty
 * is a review queue.
 */
final class PindleReviewColumn extends Column
{
    use Concerns\SummarisesReview;

    protected string $view = 'pindle::filament.review';

    /**
     * Every record on the page this column is drawing.
     *
     * A column is asked for its value one row at a time and is told nothing
     * about the other rows, so summarising each on its own is the obvious
     * implementation and a quadratic one: three queries and a whole-file hash
     * per record. Handing the whole page to the cache turns forty badges into
     * two queries, and the rows Filament has already loaded to draw the table
     * cost nothing to look at again.
     *
     * @return iterable<Model>
     */
    protected function pageOfRecords(): iterable
    {
        try {
            $records = $this->getTable()->getRecords();
        } catch (Throwable) {
            // A column not attached to a table: every column briefly, while one
            // is being built, and some for their whole lives in a test. It still
            // has a record and still owes an answer -- it just has nobody to
            // share the work with.
            return [];
        }

        return $records instanceof Collection ? $records : $records->items();
    }
}
