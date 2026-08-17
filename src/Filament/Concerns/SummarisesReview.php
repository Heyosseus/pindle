<?php

declare(strict_types=1);

namespace Pindle\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Pindle\Review\ReviewCache;
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
     *
     * Routed through the request-scoped cache rather than straight at
     * `ReviewSummary::for()`, because a column is asked this once per row and
     * the honest answer for row one is the same work as the honest answer for
     * all forty. {@see ReviewCache}.
     */
    public function getReview(): ?ReviewSummary
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return null;
        }

        return app(ReviewCache::class)->for($record, $this->getDocumentKey(), $this->pageOfRecords());
    }

    /**
     * The other records being rendered alongside this one, where there are any.
     *
     * Nothing by default. An infolist entry sits on a record page and is the
     * only one of its kind there, so it has nobody to batch with and asking
     * would be a wasted question; the table column overrides this with its page.
     *
     * @return iterable<Model>
     */
    protected function pageOfRecords(): iterable
    {
        return [];
    }
}
