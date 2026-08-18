<?php

declare(strict_types=1);

use Pindle\Filament\PindleReviewColumn;
use Pindle\Tests\Filament\Fixtures\InvoiceTable;
use Pindle\Tests\Fixtures\Invoice;

/*
 * What the review column costs on an index screen.
 *
 * Left to itself a column is asked for its value once per row and knows nothing
 * about the other rows, so the obvious implementation is three queries and a
 * whole-file hash per record -- on a table of forty, a hundred and twenty
 * queries and a hundred megabytes read off disk to draw one column of badges.
 * These tests hold the real thing to a constant.
 */

/** A page of invoices, each carrying one open annotation. */
function annotatedInvoices(int $count): void
{
    foreach (range(1, $count) as $n) {
        annotate(
            invoiceWithDocument(contents: "%PDF-1.7 invoice {$n}", path: "invoices/{$n}.pdf"),
            [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]],
        );
    }
}

/**
 * The review column, attached to a booted table of invoices.
 *
 * Booted rather than rendered. Nothing here asserts on markup, and rendering a
 * Livewire component would drag in the whole request lifecycle to reach a table
 * that is already built by the time booting finishes.
 */
function tableColumn(bool $paginate = true): PindleReviewColumn
{
    $component = new InvoiceTable;
    $component->paginate = $paginate;
    $component->bootedInteractsWithTable();

    $table = $component->getTable();

    // Filament loads and caches the page's records to draw any row at all, so
    // that read belongs to the table rather than to the column. Doing it here
    // keeps the counts below about the badges and nothing else.
    $table->getRecords();

    $column = $table->getColumn('review');

    expect($column)->toBeInstanceOf(PindleReviewColumn::class);

    return $column;
}

it('draws a whole page of badges without a query per row', function (): void {
    annotatedInvoices(10);

    $column = tableColumn();
    $invoices = Invoice::query()->get();

    $queries = queriesDuring(function () use ($column, $invoices): void {
        foreach ($invoices as $invoice) {
            expect($column->record($invoice)->getReview()?->open)->toBe(1);
        }
    });

    // Two for the whole page: the grouped tallies and the grouped comment
    // counts. Ten rows rather than two, so that a per-row query could not be
    // mistaken for a constant.
    expect($queries)->toHaveCount(2);
});

it('batches an unpaginated table just as readily', function (): void {
    annotatedInvoices(4);

    $column = tableColumn(paginate: false);
    $invoices = Invoice::query()->get();

    $queries = queriesDuring(function () use ($column, $invoices): void {
        foreach ($invoices as $invoice) {
            expect($column->record($invoice)->getReview()?->total)->toBe(1);
        }
    });

    expect($queries)->toHaveCount(2);
});

it('still answers for a column that was never put in a table', function (): void {
    annotatedInvoices(1);

    $invoice = Invoice::query()->firstOrFail();

    expect(PindleReviewColumn::make('review')->record($invoice)->getReview()?->open)->toBe(1);
});
