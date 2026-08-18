<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Review\ReviewCache;
use Pindle\Review\ReviewSummary;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\Report;

/*
 * The cost of the index screen.
 *
 * A badge saying "3 open" against two rows out of forty is the reason to install
 * this package, and it is also the easiest thing in it to make accidentally
 * quadratic -- a table column is asked for its value one row at a time and has
 * no idea it is one of forty. These tests are what stop that regressing quietly:
 * they count the queries.
 */

/** A rectangle, for tests that are about something other than geometry. */
function someRects(): array
{
    return [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]];
}

it('summarises a whole page of records in a fixed number of queries', function (): void {
    $invoices = collect(range(1, 12))->map(
        static fn (int $n): Invoice => invoiceWithDocument(path: "invoices/{$n}.pdf"),
    );

    foreach ($invoices as $invoice) {
        annotate($invoice, someRects());
    }

    $queries = queriesDuring(function () use ($invoices): void {
        expect(ReviewSummary::forMany($invoices))->toHaveCount(12);
    });

    // Two: the grouped tallies, and the grouped comment counts. Twelve records
    // rather than two, so that a per-record query would show up as a number
    // nobody could mistake for a constant.
    expect($queries)->toHaveCount(2);
});

it('asks nothing of the database for a page with no rows on it', function (): void {
    $queries = queriesDuring(function (): void {
        expect(ReviewSummary::forMany([]))->toBe([]);
    });

    expect($queries)->toBe([]);
});

it('gives the same answer batched as it does one at a time', function (): void {
    $marked = invoiceWithDocument(path: 'invoices/marked.pdf');
    $quiet = invoiceWithDocument(path: 'invoices/quiet.pdf');
    $settled = invoiceWithDocument(path: 'invoices/settled.pdf');

    annotate($marked, someRects());
    comment(annotate($marked, someRects()), 'Still disputed.');
    annotate($settled, someRects())->update(['resolved_at' => now()]);

    $batched = ReviewSummary::forMany([$marked, $quiet, $settled]);

    foreach ([$marked, $quiet, $settled] as $invoice) {
        expect($batched[(string) $invoice->getKey()]->toArray())
            ->toBe(ReviewSummary::for($invoice)->toArray());
    }
});

it('does not read a document nobody has written on', function (): void {
    $invoice = invoiceWithDocument(path: 'invoices/unmarked.pdf');

    // Nothing is anchored to it, so there is no stored hash for the file to
    // disagree with. Deleting it proves the summary never went looking: one
    // that hashed unconditionally would throw right here.
    Storage::disk('documents')->delete('invoices/unmarked.pdf');

    $summary = ReviewSummary::for($invoice);

    expect($summary->isEmpty())->toBeTrue()
        ->and($summary->orphaned)->toBe(0);
});

it('keeps records of different classes apart', function (): void {
    $invoice = invoiceWithDocument(path: 'invoices/mixed.pdf');
    $report = Report::query()->create(['pdf_path' => 'invoices/mixed.pdf']);

    annotate($invoice, someRects());

    $cache = app(ReviewCache::class);

    expect($cache->for($invoice, 'default', [$invoice, $report])->total)->toBe(1)
        ->and($cache->for($report, 'default', [$invoice, $report])->total)->toBe(0);
});

it('answers a record it was never handed a batch for', function (): void {
    $invoice = invoiceWithDocument(path: 'invoices/lonely.pdf');

    annotate($invoice, someRects());

    expect(app(ReviewCache::class)->for($invoice)->total)->toBe(1);
});

it('remembers a page of summaries rather than working each row out again', function (): void {
    $invoices = collect(range(1, 6))->map(
        static fn (int $n): Invoice => invoiceWithDocument(path: "invoices/row-{$n}.pdf"),
    );

    foreach ($invoices as $invoice) {
        annotate($invoice, someRects());
    }

    $cache = app(ReviewCache::class);

    $queries = queriesDuring(function () use ($cache, $invoices): void {
        // Exactly what a table column does: ask once per row, handing over the
        // page each time.
        foreach ($invoices as $invoice) {
            expect($cache->for($invoice, 'default', $invoices)->total)->toBe(1);
        }
    });

    expect($queries)->toHaveCount(2);
});
