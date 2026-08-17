<?php

declare(strict_types=1);

use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Pindle\Filament\PindleEntry;
use Pindle\Filament\PindlePlugin;
use Pindle\Filament\PindleViewer;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
});

/*
 * No teardown is needed for the plugin's `configureUsing` registrations:
 * Filament keeps them on a container-resolved manager, and Testbench builds a
 * fresh application per test, so they cannot leak into the next one.
 */
it('is a form field and an infolist entry, not a viewer of its own', function (): void {
    expect(PindleViewer::make('pdf_path'))->toBeInstanceOf(Field::class)
        ->and(PindleEntry::make('pdf_path'))->toBeInstanceOf(Entry::class);
});

it('never writes anything back through the form', function (): void {
    expect(PindleViewer::make('pdf_path')->isDehydrated())->toBeFalse();
});

it('takes the document key from its own name by default', function (): void {
    expect(PindleViewer::make('default')->getDocumentKey())->toBe('default')
        ->and(PindleEntry::make('delivery_note')->getDocumentKey())->toBe('delivery_note');
});

it('takes a document key that differs from the field name', function (): void {
    expect(PindleViewer::make('pdf_path')->documentKey('delivery_note')->getDocumentKey())
        ->toBe('delivery_note');
});

it('falls back to the name when the key evaluates to nothing', function (): void {
    expect(PindleViewer::make('pdf_path')->documentKey(fn (): string => '')->getDocumentKey())
        ->toBe('pdf_path');
});

it('takes a height and a readonly flag, including as closures', function (): void {
    $field = PindleViewer::make('pdf_path')->viewerHeight(fn (): int => 640)->readonly(fn (): bool => true);

    expect($field->getViewerHeight())->toBe(640)
        ->and($field->isViewerReadonly())->toBeTrue();
});

it('refuses a height that is not one', function (): void {
    expect(PindleViewer::make('pdf_path')->viewerHeight(fn (): int => 0)->getViewerHeight())->toBe(800);
});

it('is open for annotation unless told otherwise', function (): void {
    expect(PindleViewer::make('pdf_path')->isViewerReadonly())->toBeFalse();
});

it('has no record on a create form, and says so rather than erroring', function (): void {
    // A create form knows the model class but has no row yet, which is what a
    // class-string model means to Filament.
    expect(PindleViewer::make('pdf_path')->model(Invoice::class)->getViewerRecord())->toBeNull();
});

it('renders the same viewer the blade component does', function (): void {
    $this->actingAs($this->reviewer);

    $field = PindleViewer::make('pdf_path')->model($this->invoice);

    expect($field->getViewerRecord()?->getKey())->toBe($this->invoice->id);
});

it('lets a panel set the defaults for every viewer on it', function (): void {
    PindlePlugin::make()->viewerHeight(640)->readonly()->register(Panel::make());

    expect(PindleViewer::make('pdf_path')->getViewerHeight())->toBe(640)
        ->and(PindleViewer::make('pdf_path')->isViewerReadonly())->toBeTrue()
        ->and(PindleEntry::make('pdf_path')->getViewerHeight())->toBe(640);
});

it('lets a field override what the panel decided', function (): void {
    PindlePlugin::make()->viewerHeight(640)->readonly()->register(Panel::make());

    expect(PindleViewer::make('pdf_path')->viewerHeight(900)->readonly(false)->isViewerReadonly())
        ->toBeFalse();
});

it('identifies itself to the panel and adds nothing to it', function (): void {
    $plugin = PindlePlugin::make();
    $panel = Panel::make();

    expect($plugin->getId())->toBe('pindle');

    $plugin->boot($panel);

    expect($panel->getPages())->toBe([]);
});
