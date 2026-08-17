<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Documents\DocumentMap;
use Pindle\Models\Annotation;
use Pindle\Tests\Fixtures\Report;
use Pindle\Tests\Fixtures\User;

it('finds no documents on a model that never asked for any', function (): void {
    expect(DocumentMap::for(new User))->toBe([]);
});

it('falls back rather than throwing when the map was declared wrong', function (): void {
    Storage::disk('documents')->put('reports/1.pdf', '%PDF report');

    $report = Report::query()->create(['pdf_path' => 'reports/1.pdf']);

    expect(DocumentMap::for($report))->toBe(['default' => 'pdf_path'])
        ->and($report->pindleDocument()?->path)->toBe('reports/1.pdf');
});

it('drops a malformed entry without losing the rest of the map', function (): void {
    $map = DocumentMap::for(new class extends Report
    {
        /** @var mixed */
        protected $pindleDocuments = ['default' => 'pdf_path', '' => 'nowhere', 'appendix' => ''];
    });

    expect($map)->toBe(['default' => 'pdf_path']);
});

it('reads its way back to the model it was written on', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect($annotation->annotatable()->first()?->getKey())->toBe($invoice->id);
});

it('reads an anchor column that holds nothing back as an empty set', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Annotation::query()->whereKey($annotation->id)->toBase()->update(['rects' => '']);

    expect($annotation->fresh()?->rects->isEmpty())->toBeTrue();
});

it('reads an anchor column that holds something else back as an empty set', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Annotation::query()->whereKey($annotation->id)->toBase()->update(['rects' => '"not-an-array"']);

    expect($annotation->fresh()?->rects->isEmpty())->toBeTrue();
});

it('accepts anchors given as any iterable', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['rects' => new ArrayIterator([['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]])]);

    expect($annotation->fresh()?->rects->toArray())
        ->toBe([['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);
});

it('reads anchors given as nothing at all back as an empty set', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['rects' => null]);

    expect($annotation->fresh()?->rects->isEmpty())->toBeTrue();
});
