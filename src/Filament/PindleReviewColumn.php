<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Filament\Tables\Columns\Column;

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
}
