<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Filament\Infolists\Components\Entry;

/**
 * ```php
 * PindleReviewEntry::make('review')
 * ```
 *
 * The same summary on a record page, where it usually sits above the viewer:
 * what is outstanding, before you scroll a hundred pages looking for it.
 */
final class PindleReviewEntry extends Entry
{
    use Concerns\SummarisesReview;

    protected string $view = 'pindle::filament.review';
}
