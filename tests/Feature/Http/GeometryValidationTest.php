<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

/*
 * The client that drew the geometry is not a source of truth about where it
 * drew, so every one of these is checked again on the server.
 */
beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
});

/** A two-page A4 document whose structure a shallow reader can actually see. */
function twoPagePdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
        ."4 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function readableInvoice(): Invoice
{
    Storage::disk('documents')->put('invoices/readable.pdf', twoPagePdf());

    return Invoice::query()->create(['tenant_id' => 1, 'pdf_path' => 'invoices/readable.pdf']);
}

it('accepts an anchor that sits on a page the document has', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), annotationPayload(readableInvoice()) + [
            'page' => 2,
        ])
        ->assertCreated();
});

it('refuses a page the document does not have', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'page' => 3,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');
});

it('refuses an anchor that falls off the edge of the page', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'rects' => [['x1' => 500.0, 'y1' => 600.0, 'x2' => 900.0, 'y2' => 615.0]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('refuses an anchor at a negative coordinate', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'rects' => [['x1' => -10.0, 'y1' => 600.0, 'x2' => 300.0, 'y2' => 615.0]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('refuses a page number below the first', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'page' => 0,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');
});

it('caps how many anchors one annotation may carry', function (): void {
    config()->set('pindle.annotations.max_rects', 2);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'rects' => array_fill(0, 3, ['x1' => 10.0, 'y1' => 10.0, 'x2' => 20.0, 'y2' => 20.0]),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('holds a note to the one rectangle it can be drawn at', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'type' => 'note',
            'rects' => [
                ['x1' => 10.0, 'y1' => 10.0, 'x2' => 20.0, 'y2' => 20.0],
                ['x1' => 30.0, 'y1' => 30.0, 'x2' => 40.0, 'y2' => 40.0],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('lets a highlight run across as many lines as it needs', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'rects' => [
                ['x1' => 72.0, 'y1' => 640.0, 'x2' => 520.0, 'y2' => 655.0],
                ['x1' => 72.0, 'y1' => 622.0, 'x2' => 520.0, 'y2' => 637.0],
                ['x1' => 72.0, 'y1' => 604.0, 'x2' => 310.0, 'y2' => 619.0],
            ],
        ]))
        ->assertCreated();
});

it('refuses a type this installation does not allow', function (): void {
    config()->set('pindle.annotations.types', ['highlight']);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'type' => 'ink',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('refuses a type that is not one of the four at all', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'type' => 'stamp',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('refuses an anchor that is not four numbers', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'rects' => [['x1' => 'left', 'y1' => 2, 'x2' => 3, 'y2' => 4]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects.0.x1');
});

it('refuses a colour that is not a colour', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload(readableInvoice()), [
            'color' => 'javascript:alert(1)',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('color');
});

it('falls back to the configured ceiling for a document it cannot read', function (): void {
    // The fake PDF in the ordinary fixtures has no page tree to find, so bounds
    // are unknown and the configured cap is what applies instead.
    config()->set('pindle.annotations.max_pages', 3);

    $invoice = invoiceWithDocument();
    $invoice->update(['tenant_id' => 1]);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload($invoice), ['page' => 3]))
        ->assertCreated();

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload($invoice), ['page' => 4]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');
});

it('still refuses an anchor beyond any page a PDF may have', function (): void {
    $invoice = invoiceWithDocument();
    $invoice->update(['tenant_id' => 1]);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload($invoice), [
            'rects' => [['x1' => 10.0, 'y1' => 10.0, 'x2' => 20_000.0, 'y2' => 20.0]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('checks the geometry again when an annotation is moved', function (): void {
    $invoice = readableInvoice();

    $annotation = annotate($invoice, [['x1' => 10.0, 'y1' => 10.0, 'x2' => 20.0, 'y2' => 20.0]]);

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', $annotation->id), [
            'rects' => [['x1' => 10.0, 'y1' => 10.0, 'x2' => 900.0, 'y2' => 20.0]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('records the document a new annotation was drawn on when there is none', function (): void {
    $invoice = Invoice::query()->create(['tenant_id' => 1]);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), annotationPayload($invoice))
        ->assertCreated()
        ->assertJsonPath('orphaned', false);
});
