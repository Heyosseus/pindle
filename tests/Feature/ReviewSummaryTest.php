<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Review\ReviewSummary;
use Pindle\Tests\Fixtures\Invoice;

it('says nothing is on a document nobody has marked', function (): void {
    $review = invoiceWithDocument()->pindleReview();

    expect($review->isEmpty())->toBeTrue()
        ->and($review->total)->toBe(0)
        ->and($review->isSettled())->toBeTrue()
        ->and($review->needsAttention())->toBeFalse()
        ->and($review->label())->toBe('No marks');
});

it('counts what is open against what is settled', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]])
        ->update(['resolved_at' => now()]);

    $review = $invoice->pindleReview();

    expect($review->total)->toBe(2)
        ->and($review->open)->toBe(1)
        ->and($review->resolved)->toBe(1)
        ->and($review->orphaned)->toBe(0)
        ->and($review->isSettled())->toBeFalse()
        ->and($review->label())->toBe('1 open · 1 resolved');
});

it('counts the comments on the whole document', function (): void {
    $invoice = invoiceWithDocument();

    $first = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    $second = annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);

    comment($first, 'One.');
    comment($first, 'Two.');
    comment($second, 'Three.');

    expect($invoice->pindleReview()->comments)->toBe(3);
});

it('counts what no longer points at the right place', function (): void {
    $invoice = invoiceWithDocument('%PDF-1.7 first issue');

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);

    expect($invoice->pindleReview()->orphaned)->toBe(0);

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 revision B');

    $review = $invoice->pindleReview();

    expect($review->orphaned)->toBe(2)
        ->and($review->needsAttention())->toBeTrue()
        ->and($review->label())->toContain('2 orphaned');
});

it('still wants attention for a settled objection to text that has since moved', function (): void {
    $invoice = invoiceWithDocument('%PDF-1.7 first issue');

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]])
        ->update(['resolved_at' => now()]);

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 revision B');

    $review = $invoice->pindleReview();

    expect($review->open)->toBe(0)
        ->and($review->orphaned)->toBe(1)
        ->and($review->isSettled())->toBeFalse();
});

it('orphans nothing when there is no document to disagree with', function (): void {
    $invoice = Invoice::query()->create([]);

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($invoice->pindleReview()->orphaned)->toBe(0);
});

it('keeps one document\'s review out of another\'s', function (): void {
    Storage::disk('documents')->put('invoices/2.pdf', '%PDF invoice');
    Storage::disk('documents')->put('notes/2.pdf', '%PDF delivery note');

    $invoice = Invoice::query()->create([
        'pdf_path' => 'invoices/2.pdf',
        'delivery_pdf_path' => 'notes/2.pdf',
    ]);

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]], 'delivery_note');
    annotate($invoice, [['x1' => 9.0, 'y1' => 9.0, 'x2' => 9.0, 'y2' => 9.0]], 'delivery_note');

    $reviews = $invoice->pindleReviews();

    expect(array_keys($reviews))->toBe(['default', 'delivery_note'])
        ->and($reviews['default']->total)->toBe(1)
        ->and($reviews['delivery_note']->total)->toBe(2)
        ->and($reviews['delivery_note']->documentKey)->toBe('delivery_note');
});

it('records when anything last moved', function (): void {
    $invoice = invoiceWithDocument();

    expect($invoice->pindleReview()->lastActivityAt)->toBeNull();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($invoice->pindleReview()->lastActivityAt)->not->toBeNull();
});

it('leaves deleted marks out of the count', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]])->delete();

    expect($invoice->pindleReview()->total)->toBe(0);
});

it('serialises to something an api or a job can carry', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $payload = json_decode((string) json_encode($invoice->pindleReview()), true);

    expect($payload)->toHaveKeys(['document_key', 'total', 'open', 'resolved', 'orphaned', 'comments', 'settled'])
        ->and($payload['settled'])->toBeFalse();
});

it('can be built for any model without going through the trait', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect(ReviewSummary::for($invoice)->total)->toBe(1);
});
