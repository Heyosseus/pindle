<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pindle\Events\AnnotationReanchored;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

/*
 * Moving an orphan onto the document that replaced its own. This is the one
 * request that deliberately rewrites the hash an orphan is judged by, so it is
 * held to a higher standard than the rest: explicit endpoint, explicit
 * authorisation, its own event, and the hash taken from the bytes.
 */
beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->original = twoPagePdf();

    Storage::disk('documents')->put('invoices/c.pdf', $this->original);

    $this->invoice = Invoice::query()->create(['tenant_id' => 1, 'pdf_path' => 'invoices/c.pdf']);
    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    $this->annotation = annotate($this->invoice, [['x1' => 72.0, 'y1' => 640.0, 'x2' => 300.0, 'y2' => 654.0]]);
    $this->annotation->update(['text_snippet' => 'payable within thirty days']);

    // The contract is re-issued.
    Storage::disk('documents')->put('invoices/c.pdf', $this->original.str_repeat("\n% revision B", 4));
});

it('moves the mark and re-hashes it against the new bytes', function (): void {
    $before = $this->annotation->document_hash;

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 2,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertOk()
        ->assertJsonPath('page', 2)
        ->assertJsonPath('orphaned', false)
        ->assertJsonPath('rects.0.x1', 80.0);

    $moved = $this->annotation->fresh();

    expect($moved?->document_hash)->not->toBe($before)
        ->and($moved?->document_hash)->toBe($this->invoice->pindleDocument()?->hash())
        ->and($moved?->isOrphanedFrom($this->invoice->pindleDocument()))->toBeFalse();
});

it('keeps the thread, the author and the type it always had', function (): void {
    comment($this->annotation, 'This clause is wrong.');

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertOk()
        ->assertJsonPath('type', 'highlight')
        ->assertJsonPath('comments.0.body', 'This clause is wrong.')
        ->assertJsonPath('text_snippet', 'payable within thirty days');
});

it('announces the move as its own event, carrying the hash it replaced', function (): void {
    Event::fake([AnnotationReanchored::class]);

    $before = $this->annotation->document_hash;

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertOk();

    Event::assertDispatched(
        AnnotationReanchored::class,
        fn (AnnotationReanchored $event): bool => $event->annotation->is($this->annotation)
            && $event->previousHash === $before
            && $event->actor?->getAuthIdentifier() === $this->reviewer->id,
    );
});

it('ignores a hash the client tried to supply', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
            'document_hash' => str_repeat('a', 64),
        ])
        ->assertOk();

    expect($this->annotation->fresh()?->document_hash)
        ->toBe($this->invoice->pindleDocument()?->hash());
});

it('checks the geometry against the new document, not the old one', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 9,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 9000.0, 'y2' => 615.0]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('insists on somewhere to move it to', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), ['page' => 1, 'rects' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rects');
});

it('refuses when there is no document to move it onto', function (): void {
    Storage::disk('documents')->delete('invoices/c.pdf');

    $this->invoice->update(['pdf_path' => null]);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');
});

it('refuses somebody from another tenant', function (): void {
    $stranger = User::query()->create(['name' => 'Stranger', 'tenant_id' => 2]);

    $this->actingAs($stranger)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
        ])
        ->assertForbidden();

    expect($this->annotation->fresh()?->isOrphanedFrom($this->invoice->pindleDocument()))->toBeTrue();
});

it('refuses a guest', function (): void {
    $this->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
        'page' => 1,
        'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 320.0, 'y2' => 615.0]],
    ])->assertForbidden();
});

it('answers with nothing found for an annotation that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', 'not-an-id'), [
            'page' => 1,
            'rects' => [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]],
        ])
        ->assertNotFound();
});

it('answers with nothing found when the model it belonged to has gone', function (): void {
    Invoice::query()->whereKey($this->invoice->id)->forceDelete();

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.reanchor', $this->annotation->id), [
            'page' => 1,
            'rects' => [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]],
        ])
        ->assertNotFound();
});
