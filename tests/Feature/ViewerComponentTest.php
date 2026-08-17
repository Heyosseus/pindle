<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Pindle\Support\Assets;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    app(Assets::class)->flush();

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
});

/** The settings the bundle reads off the root element. */
function settingsFrom(string $html): array
{
    expect($html)->toContain('data-pindle=');

    preg_match('/data-pindle="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true) ?? [];
}

it('renders a root the bundle will find, carrying everything it needs', function (): void {
    $this->actingAs($this->reviewer);

    $html = Blade::render('<x-pindle::viewer :for="$invoice" />', ['invoice' => $this->invoice]);

    $settings = settingsFrom($html);

    expect($settings['annotatableType'])->toBe($this->invoice->getMorphClass())
        ->and($settings['annotatableId'])->toBe((string) $this->invoice->id)
        ->and($settings['documentKey'])->toBe('default')
        ->and($settings['readonly'])->toBeFalse()
        ->and($settings['maxCommentLength'])->toBe(2000)
        ->and($settings['wasmUrl'])->toContain('vendor/pindle/pdfium.wasm')
        ->and($settings['base'])->toContain('/pindle')
        ->and($settings['csrfToken'])->toBeString();
});

it('carries the csrf token when there is a session to take one from', function (): void {
    $this->actingAs($this->reviewer);

    $this->startSession();

    // Blade::render happens outside the request lifecycle, so the session has
    // to be put on the request the way the session middleware would.
    request()->setLaravelSession(session()->driver());

    $settings = settingsFrom(Blade::render('<x-pindle::viewer :for="$invoice" />', ['invoice' => $this->invoice]));

    expect($settings['csrfToken'])->toBe(session()->token());
});

it('mints a signed, expiring document url for whoever is looking', function (): void {
    $this->actingAs($this->reviewer);

    $settings = settingsFrom(Blade::render('<x-pindle::viewer :for="$invoice" />', ['invoice' => $this->invoice]));

    expect($settings['documentUrl'])->toContain('signature=')
        ->and($settings['documentUrl'])->toContain('expires=')
        ->and($settings['documentUrl'])->toContain('/pindle/documents/');
});

it('keeps livewire away from the canvas', function (): void {
    $this->actingAs($this->reviewer);

    $html = Blade::render('<x-pindle::viewer :for="$invoice" />', ['invoice' => $this->invoice]);

    expect($html)->toContain('wire:ignore');
});

it('takes the height and readonly flag it was given', function (): void {
    $this->actingAs($this->reviewer);

    $html = Blade::render(
        '<x-pindle::viewer :for="$invoice" :height="640" :readonly="true" />',
        ['invoice' => $this->invoice],
    );

    expect($html)->toContain('height: 640px')
        ->and(settingsFrom($html)['readonly'])->toBeTrue();
});

it('names the document it was pointed at', function (): void {
    Illuminate\Support\Facades\Storage::disk('documents')->put('notes/1.pdf', '%PDF delivery note');

    $this->invoice->update(['delivery_pdf_path' => 'notes/1.pdf']);

    $this->actingAs($this->reviewer);

    $html = Blade::render(
        '<x-pindle::viewer :for="$invoice" document="delivery_note" />',
        ['invoice' => $this->invoice],
    );

    expect(settingsFrom($html)['documentKey'])->toBe('delivery_note');
});

it('says so rather than booting a viewer over nothing', function (): void {
    $this->actingAs($this->reviewer);

    $html = Blade::render(
        '<x-pindle::viewer :for="$invoice" />',
        ['invoice' => Invoice::query()->create(['tenant_id' => 1])],
    );

    expect($html)->toContain('no document to annotate')
        ->and($html)->not->toContain('data-pindle=');
});

it('says so for a document key the model does not carry', function (): void {
    $this->actingAs($this->reviewer);

    $html = Blade::render(
        '<x-pindle::viewer :for="$invoice" document="appendix" />',
        ['invoice' => $this->invoice],
    );

    expect($html)->toContain('no document to annotate');
});

it('emits the script and style tags once, however often it is asked', function (): void {
    $first = Blade::render('@pindleScripts');
    $second = Blade::render('@pindleScripts');

    expect($first)->toContain('vendor/pindle/pindle.js')
        ->and($first)->toContain('vendor/pindle/pindle.css')
        ->and($first)->toContain('defer')
        ->and($second)->toBe('');
});

it('ships a built bundle rather than expecting the application to build one', function (): void {
    $dist = __DIR__.'/../../resources/dist';

    expect(file_exists($dist.'/pindle.js'))->toBeTrue()
        ->and(file_exists($dist.'/pindle.css'))->toBeTrue()
        ->and(file_exists($dist.'/pdfium.wasm'))->toBeTrue();
});
