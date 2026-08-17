<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pindle\Models\Annotation;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
});

it('lists what is written on a document', function (): void {
    $annotation = annotate($this->invoice, [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);
    comment($annotation, 'This total does not match.');

    $this->actingAs($this->reviewer)
        ->getJson(listUrl($this->invoice))
        ->assertOk()
        ->assertJsonPath('data.0.id', $annotation->id)
        ->assertJsonPath('data.0.page', 1)
        ->assertJsonPath('data.0.type', 'highlight')
        ->assertJsonPath('data.0.orphaned', false)
        ->assertJsonPath('data.0.rects.0.x1', 72.0)
        ->assertJsonPath('data.0.rects.0.y2', 655.8)
        ->assertJsonPath('data.0.comments.0.body', 'This total does not match.')
        ->assertJsonPath('document.hash', $this->invoice->pindleDocument()?->hash());
});

it('keeps one document\'s annotations out of another\'s list', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $this->actingAs($this->reviewer)
        ->getJson(listUrl($this->invoice, 'delivery_note'))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('document', null);
});

it('flags an annotation whose document has been re-issued', function (): void {
    annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Illuminate\Support\Facades\Storage::disk('documents')->put('invoices/1.pdf', '%PDF-1.7 revision B');

    $this->actingAs($this->reviewer)
        ->getJson(listUrl($this->invoice))
        ->assertOk()
        ->assertJsonPath('data.0.orphaned', true)
        // The coordinates are untouched. Orphaned means "do not trust where this
        // points", not "we moved it".
        ->assertJsonPath('data.0.rects.0.x1', 1.0);
});

it('records a new annotation against the bytes it was drawn on', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
        ->assertCreated()
        ->assertJsonPath('type', 'highlight')
        ->assertJsonPath('orphaned', false);

    $annotation = Annotation::query()->findOrFail($response->json('id'));

    expect($annotation->document_hash)->toBe($this->invoice->pindleDocument()?->hash())
        ->and($annotation->author_id)->toBe((string) $this->reviewer->id)
        ->and($annotation->rects->toArray())->toBe([['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);
});

it('ignores a hash the client tried to choose for itself', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice) + [
            'document_hash' => str_repeat('a', 64),
        ])
        ->assertCreated();

    expect(Annotation::query()->findOrFail($response->json('id'))->document_hash)
        ->toBe($this->invoice->pindleDocument()?->hash());
});

it('moves an annotation without disturbing what it is', function (): void {
    $annotation = annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', $annotation->id), [
            'rects' => [['x1' => 80.0, 'y1' => 600.0, 'x2' => 300.0, 'y2' => 615.0]],
            'color' => '#fde047',
        ])
        ->assertOk()
        ->assertJsonPath('rects.0.x1', 80.0)
        ->assertJsonPath('color', '#fde047')
        ->assertJsonPath('type', 'highlight');
});

it('settles a point and reopens it', function (): void {
    $annotation = annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', $annotation->id), ['resolved' => true])
        ->assertOk()
        ->assertJsonPath('resolved_by_id', (string) $this->reviewer->id);

    expect($annotation->fresh()?->isResolved())->toBeTrue();

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', $annotation->id), ['resolved' => false])
        ->assertOk()
        ->assertJsonPath('resolved_at', null)
        ->assertJsonPath('resolved_by_id', null);
});

it('takes an annotation down without losing the audit trail', function (): void {
    $annotation = annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.annotations.destroy', $annotation->id))
        ->assertNoContent();

    expect(Annotation::query()->count())->toBe(0)
        ->and(Annotation::query()->withTrashed()->count())->toBe(1);
});

it('answers with nothing found for an annotation that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', 'not-an-id'), ['color' => '#fde047'])
        ->assertNotFound();
});

it('answers with nothing found for a model that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->getJson(route('pindle.annotations.index', [
            'annotatable_type' => Invoice::class,
            'annotatable_id' => '999999',
        ]))
        ->assertNotFound();
});

it('answers with nothing found for a type that names no model at all', function (): void {
    $this->actingAs($this->reviewer)
        ->getJson(route('pindle.annotations.index', [
            'annotatable_type' => 'App\\Models\\NotAThing',
            'annotatable_id' => '1',
        ]))
        ->assertNotFound();
});
