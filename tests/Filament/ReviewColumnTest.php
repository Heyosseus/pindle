<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Filament\PindleReviewColumn;
use Pindle\Filament\PindleReviewEntry;
use Pindle\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    $this->invoice = invoiceWithDocument();
});

it('summarises the record it is sitting on', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $column = PindleReviewColumn::make('review')->record($this->invoice);

    expect($column->getReview()?->open)->toBe(1)
        ->and($column->getDocumentKey())->toBe('default');
});

it('summarises on a record page as readily as in a table', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    // An entry belongs to a schema and takes its record through model(); a
    // table column is handed one row at a time through record(). Same summary.
    expect(PindleReviewEntry::make('review')->model($this->invoice)->getReview()?->total)->toBe(1);
});

it('summarises whichever document it was pointed at', function (): void {
    Storage::disk('documents')->put('notes/1.pdf', '%PDF delivery note');

    $this->invoice->update(['delivery_pdf_path' => 'notes/1.pdf']);

    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]], 'delivery_note');

    $column = PindleReviewColumn::make('review')->documentKey('delivery_note')->record($this->invoice);

    expect($column->getDocumentKey())->toBe('delivery_note')
        ->and($column->getReview()?->total)->toBe(1)
        ->and(PindleReviewColumn::make('review')->record($this->invoice)->getReview()?->total)->toBe(0);
});

it('takes a document key worked out at render time', function (): void {
    $column = PindleReviewColumn::make('review')->documentKey(fn (): string => 'delivery_note');

    expect($column->getDocumentKey())->toBe('delivery_note');
});

it('falls back to the default document when the key evaluates to nothing', function (): void {
    expect(PindleReviewColumn::make('review')->documentKey(fn (): string => '')->getDocumentKey())
        ->toBe('default');
});

it('has nothing to summarise without a record', function (): void {
    expect(PindleReviewColumn::make('review')->getReview())->toBeNull();
});

it('counts orphans separately from what is merely open', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF revision B');

    $review = PindleReviewColumn::make('review')->record($this->invoice)->getReview();

    expect($review?->orphaned)->toBe(1)
        ->and($review?->needsAttention())->toBeTrue();
});

it('renders badges saying what is outstanding', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $html = PindleReviewColumn::make('review')->record($this->invoice)->toHtml();

    expect($html)->toContain('pindle-review__badge--open')
        ->and($html)->toContain('1 open');
});

it('says so plainly when nobody has marked the document', function (): void {
    $html = PindleReviewColumn::make('review')->record($this->invoice)->toHtml();

    expect($html)->toContain('No marks');
});

it('shows a settled badge once everything is closed', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]])
        ->update(['resolved_at' => now()]);

    $html = PindleReviewColumn::make('review')->record($this->invoice)->toHtml();

    expect($html)->toContain('pindle-review__badge--settled')
        ->and($html)->not->toContain('pindle-review__badge--open');
});

it('renders nothing alarming for a record with no document at all', function (): void {
    $html = PindleReviewColumn::make('review')->record(Invoice::query()->create([]))->toHtml();

    expect($html)->toContain('No marks');
});
