<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Documents\PindleDocument;
use Pindle\Exceptions\DocumentUnreadable;
use Pindle\Tests\Fixtures\Contract;
use Pindle\Tests\Fixtures\Invoice;

it('resolves the document named in the map', function (): void {
    $invoice = invoiceWithDocument();

    $document = $invoice->pindleDocument();

    expect($document)->toBeInstanceOf(PindleDocument::class)
        ->and($document?->disk)->toBe('documents')
        ->and($document?->path)->toBe('invoices/1.pdf')
        ->and($document?->key)->toBe('default')
        ->and($document?->filename())->toBe('1.pdf')
        ->and($document?->mimeType)->toBe('application/pdf');
});

it('keeps a model\'s several documents apart', function (): void {
    Storage::disk('documents')->put('invoices/2.pdf', '%PDF invoice');
    Storage::disk('documents')->put('notes/2.pdf', '%PDF delivery note');

    $invoice = Invoice::query()->create([
        'pdf_path' => 'invoices/2.pdf',
        'delivery_pdf_path' => 'notes/2.pdf',
    ]);

    expect($invoice->pindleDocument()?->path)->toBe('invoices/2.pdf')
        ->and($invoice->pindleDocument('delivery_note')?->path)->toBe('notes/2.pdf')
        ->and($invoice->pindleDocumentKeys())->toBe(['default', 'delivery_note']);
});

it('falls back to the conventional column when a model declares no map', function (): void {
    Storage::disk('documents')->put('contracts/a.pdf', '%PDF contract');

    $contract = Contract::query()->create(['pdf_path' => 'contracts/a.pdf']);

    expect($contract->pindleDocument()?->path)->toBe('contracts/a.pdf')
        ->and($contract->pindleDocumentKeys())->toBe(['default']);
});

it('has no document under a key the model never declared', function (): void {
    expect(invoiceWithDocument()->pindleDocument('appendix'))->toBeNull();
});

it('has no document when the path has not been filled in yet', function (): void {
    expect(Invoice::query()->create([])->pindleDocument())->toBeNull();
});

it('reports a document the disk does not hold', function (): void {
    $invoice = Invoice::query()->create(['pdf_path' => 'invoices/missing.pdf']);

    expect($invoice->pindleDocument()?->exists())->toBeFalse();
});

it('hashes the bytes rather than the path', function (): void {
    $invoice = invoiceWithDocument('%PDF-1.7 first issue');

    expect($invoice->pindleDocument()?->hash())
        ->toBe(hash('sha256', '%PDF-1.7 first issue'));
});

it('hashes lazily and only once', function (): void {
    $document = invoiceWithDocument()->pindleDocument();

    $first = $document?->hash();

    // The file changes underneath a document that has already been asked. It
    // keeps its answer, because the object is the reading and not the file.
    Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 re-issued');

    expect($document?->hash())->toBe($first);
});

it('gives a re-read of a replaced document a different hash', function (): void {
    $invoice = invoiceWithDocument('%PDF-1.7 first issue');

    $before = $invoice->pindleDocument()?->hash();

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 re-issued');

    expect($invoice->pindleDocument()?->hash())->not->toBe($before);
});

it('answers whether it is still the document an annotation was drawn on', function (): void {
    $document = invoiceWithDocument()->pindleDocument();

    expect($document?->matches(hash('sha256', '%PDF-1.7 first issue')))->toBeTrue()
        ->and($document?->matches(str_repeat('0', 64)))->toBeFalse();
});

it('knows how many bytes it is, which range requests need', function (): void {
    $document = invoiceWithDocument('%PDF-1.7 first issue')->pindleDocument();

    expect($document?->size())->toBe(strlen('%PDF-1.7 first issue'));
});

it('has no size when there is nothing there', function (): void {
    expect((new PindleDocument('documents', 'nowhere.pdf'))->size())->toBe(0);
});

it('refuses to hash a document the disk cannot open', function (): void {
    (new PindleDocument('documents', 'nowhere.pdf'))->hash();
})->throws(DocumentUnreadable::class, 'could not read [nowhere.pdf]');
