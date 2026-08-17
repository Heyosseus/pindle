<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Enums\AnnotationType;
use Pindle\Geometry\Rect;
use Pindle\Geometry\Rects;
use Pindle\Models\Annotation;
use Pindle\Pindle;
use Pindle\Tests\Fixtures\Contract;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\User;

/**
 * The anchoring round trip. If any of this drifts, every highlight in every
 * install lands somewhere other than where it was drawn, so it is asserted to
 * the exact float rather than to a tolerance.
 */
it('returns the coordinates it was given, unchanged', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = annotate($invoice, [
        ['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8],
        ['x1' => 72.0, 'y1' => 622.4, 'x2' => 519.25, 'y2' => 637.9],
    ]);

    $stored = Annotation::query()->findOrFail($annotation->id);

    expect($stored->rects->toArray())->toBe([
        ['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8],
        ['x1' => 72.0, 'y1' => 622.4, 'x2' => 519.25, 'y2' => 637.9],
    ]);
});

it('persists nothing that depends on the scale it was drawn at', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);

    $columns = array_keys($annotation->fresh()?->getAttributes() ?? []);

    // Nothing here may name a zoom, a rotation, a viewport or a device ratio.
    // A column that did would be a column somebody eventually anchors to.
    expect($columns)->not->toContain('scale', 'zoom', 'rotation', 'viewport_width', 'device_pixel_ratio');
});

it('sorts a backwards selection on the way in and keeps it sorted', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 310.5, 'y1' => 655.8, 'x2' => 72.0, 'y2' => 640.2]]);

    expect($annotation->fresh()?->rects->toArray())
        ->toBe([['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);
});

it('hands back a value object rather than a bare array', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($annotation->fresh()?->rects)->toBeInstanceOf(Rects::class)
        ->and($annotation->fresh()?->rects->rects[0])->toBeInstanceOf(Rect::class);
});

it('accepts a set of rectangles as the value object too', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = Annotation::query()->create([
        'annotatable_type' => $invoice->getMorphClass(),
        'annotatable_id' => (string) $invoice->getKey(),
        'document_key' => 'default',
        'document_hash' => $invoice->pindleDocument()?->hash(),
        'page' => 1,
        'type' => AnnotationType::Highlight,
        'rects' => Rects::make([new Rect(72.0, 640.2, 310.5, 655.8)]),
        'author_type' => 'user',
        'author_id' => '1',
    ]);

    expect($annotation->fresh()?->rects->toArray())
        ->toBe([['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);
});

it('reads an empty anchor set back as empty', function (): void {
    $annotation = annotate(invoiceWithDocument(), []);

    expect($annotation->fresh()?->rects->isEmpty())->toBeTrue();
});

it('is not orphaned while the document is the one it was drawn on', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($annotation->isOrphanedFrom($invoice->pindleDocument()))->toBeFalse();
});

it('is orphaned once the document behind it is replaced', function (): void {
    $invoice = invoiceWithDocument('%PDF-1.7 first issue');

    $annotation = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 re-issued, clause 4 rewritten');

    expect($annotation->isOrphanedFrom($invoice->pindleDocument()))->toBeTrue();
});

it('keeps the text under the anchor so an orphan could one day be re-found', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['text_snippet' => 'payable within thirty days']);

    expect($annotation->fresh()?->text_snippet)->toBe('payable within thirty days');
});

it('anchors to a model keyed by ulid as readily as one keyed by an integer', function (): void {
    Storage::disk('documents')->put('contracts/a.pdf', '%PDF contract');

    $contract = Contract::query()->create(['pdf_path' => 'contracts/a.pdf']);

    $annotation = annotate($contract, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($annotation->annotatable_id)->toBe($contract->id)
        ->and($contract->annotations()->count())->toBe(1);
});

it('keys itself by ulid', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($annotation->id)->toHaveLength(26)
        ->and($annotation->getIncrementing())->toBeFalse();
});

it('separates the annotations on one document from those on another', function (): void {
    Storage::disk('documents')->put('invoices/2.pdf', '%PDF invoice');
    Storage::disk('documents')->put('notes/2.pdf', '%PDF delivery note');

    $invoice = Invoice::query()->create([
        'pdf_path' => 'invoices/2.pdf',
        'delivery_pdf_path' => 'notes/2.pdf',
    ]);

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]], 'delivery_note');

    expect($invoice->annotations()->count())->toBe(2)
        ->and($invoice->annotationsFor()->count())->toBe(1)
        ->and($invoice->annotationsFor('delivery_note')->count())->toBe(1);
});

it('finds the annotations on one document through the scope', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect(Annotation::query()->forDocument($invoice)->count())->toBe(1)
        ->and(Annotation::query()->forDocument($invoice, 'delivery_note')->count())->toBe(0);
});

it('lets the application layer another constraint onto every query', function (): void {
    $invoice = invoiceWithDocument();

    annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]], 'default', 2);

    expect(Pindle::query()->count())->toBe(2);

    Pindle::scopeUsing(fn ($query) => $query->where('page', 2));

    expect(Pindle::query()->count())->toBe(1);

    Pindle::scopeUsing(null);

    expect(Pindle::query()->count())->toBe(2);
});

it('separates what is settled from what is still open', function (): void {
    $invoice = invoiceWithDocument();

    $open = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    $settled = annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);

    $settled->update(['resolved_at' => now(), 'resolved_by_id' => '1']);

    expect($open->fresh()?->isResolved())->toBeFalse()
        ->and($settled->fresh()?->isResolved())->toBeTrue()
        ->and(Annotation::query()->unresolved()->count())->toBe(1);
});

it('keeps a deleted annotation as an audit trail', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->delete();

    expect(Annotation::query()->count())->toBe(0)
        ->and(Annotation::query()->withTrashed()->count())->toBe(1);
});

it('records who drew it', function (): void {
    $user = User::query()->create(['name' => 'Reviewer']);

    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]], 'default', 1, $user);

    expect($annotation->author()->first()?->getKey())->toBe($user->id);
});
