<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Pindle\Livewire\PindleViewer;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    $this->actingAs($this->reviewer);
});

it('registers itself under the name the documentation uses', function (): void {
    expect(Livewire::new('pindle-viewer'))->toBeInstanceOf(PindleViewer::class);
});

it('renders the viewer, with livewire kept off the canvas', function (): void {
    Livewire::test(PindleViewer::class, ['for' => $this->invoice])
        ->assertSee('wire:ignore', escape: false)
        ->assertSee('data-pindle=', escape: false);
});

it('carries only the morph and the key across the wire', function (): void {
    Livewire::test(PindleViewer::class, ['for' => $this->invoice])
        ->assertSet('annotatableType', Invoice::class)
        ->assertSet('annotatableId', (string) $this->invoice->id)
        ->assertSet('document', 'default')
        ->assertSet('readonly', false);
});

it('bridges the browser\'s events to livewire ones', function (): void {
    $html = Livewire::test(PindleViewer::class, ['for' => $this->invoice])->html();

    expect($html)->toContain('pindle:')
        ->and($html)->toContain('addEventListener');
});

it('takes the document, height and readonly flag it was mounted with', function (): void {
    Illuminate\Support\Facades\Storage::disk('documents')->put('notes/1.pdf', '%PDF note');

    $this->invoice->update(['delivery_pdf_path' => 'notes/1.pdf']);

    Livewire::test(PindleViewer::class, [
        'for' => $this->invoice,
        'document' => 'delivery_note',
        'height' => 640,
        'readonly' => true,
    ])
        ->assertSet('document', 'delivery_note')
        ->assertSet('height', 640)
        ->assertSet('readonly', true)
        ->assertSee('height: 640px', escape: false);
});

it('survives a re-render without duplicating the viewer', function (): void {
    $html = Livewire::test(PindleViewer::class, ['for' => $this->invoice])
        ->refresh()
        ->html();

    expect(substr_count($html, 'data-pindle='))->toBe(1);
});

it('says so when the model it was pointed at has gone', function (): void {
    $component = Livewire::test(PindleViewer::class, ['for' => $this->invoice]);

    Invoice::query()->whereKey($this->invoice->id)->forceDelete();

    $component->refresh()->assertSee('no document to annotate');
});

it('refuses to be pointed at another model from the browser', function (): void {
    // The morph and the key are locked, so a tampered payload cannot turn a
    // viewer of one tenant's invoice into a viewer of another's.
    expect(fn () => Livewire::test(PindleViewer::class, ['for' => $this->invoice])
        ->set('annotatableType', DateTimeImmutable::class))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
