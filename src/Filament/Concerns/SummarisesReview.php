<?php

declare(strict_types=1);

namespace Pindle\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Pindle\Review\ReviewSummary;

/**
 * The review of whatever record this column or entry is sitting on.
 *
 * Shared by the table column and the infolist entry, which differ only in what
 * they extend. Both answer the question a reviewer actually opens the admin
 * panel to ask -- "which of these has somebody objected to?" -- from the index
 * screen, without opening a single document.
 */
trait SummarisesReview
{
    protected string|Closure|null $pindleDocumentKey = null;

    public function documentKey(string|Closure|null $key): static
    {
        $this->pindleDocumentKey = $key;

        return $this;
    }

    public function getDocumentKey(): string
    {
        $key = $this->evaluate($this->pindleDocumentKey);

        return is_string($key) && $key !== '' ? $key : 'default';
    }

    /**
     * Null where there is no record yet -- a create form, an empty table row.
     */
    public function getReview(): ?ReviewSummary
    {
        $record = $this->getRecord();

        return $record instanceof Model ? ReviewSummary::for($record, $this->getDocumentKey()) : null;
    }
}
