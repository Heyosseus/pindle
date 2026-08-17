<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Pindle\Review\ReviewExport;
use Pindle\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    $this->invoice = invoiceWithDocument();

    $this->first = annotate($this->invoice, [['x1' => 72.0, 'y1' => 640.0, 'x2' => 300.0, 'y2' => 654.0]]);
    $this->first->update(['text_snippet' => 'payable within thirty days']);

    $root = comment($this->first, 'The purchase order says sixty.');
    comment($this->first, 'Confirmed, revision B is on its way.', $root);

    $this->second = annotate($this->invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]], 'default', 3);
    $this->second->update(['resolved_at' => now()]);
});

it('writes a review somebody can read', function (): void {
    $markdown = (new ReviewExport($this->invoice))->toMarkdown();

    expect($markdown)->toContain('# Review of Invoice #'.$this->invoice->id)
        ->and($markdown)->toContain('2 mark(s) — 1 open, 1 resolved')
        ->and($markdown)->toContain('## Page 1')
        ->and($markdown)->toContain('## Page 3')
        ->and($markdown)->toContain('> payable within thirty days')
        ->and($markdown)->toContain('The purchase order says sixty.')
        ->and($markdown)->toContain('  - **1**');
});

it('orders the review the way a reader goes through the document', function (): void {
    $markdown = (new ReviewExport($this->invoice))->toMarkdown();

    expect(strpos($markdown, '## Page 1'))->toBeLessThan(strpos($markdown, '## Page 3'));
});

it('says out loud when marks no longer point at the right place', function (): void {
    Storage::disk('documents')->put('invoices/1.pdf', '%PDF revision B');

    expect((new ReviewExport($this->invoice))->toMarkdown())
        ->toContain('has since been replaced');
});

it('writes the same review as data', function (): void {
    $payload = json_decode((new ReviewExport($this->invoice))->toJson(), true);

    expect($payload['annotatable']['id'])->toBe((string) $this->invoice->id)
        ->and($payload['summary']['total'])->toBe(2)
        ->and($payload['annotations'])->toHaveCount(2)
        ->and($payload['annotations'][0]['comments'])->toHaveCount(2)
        ->and($payload['annotations'][0]['rects'][0]['x1'])->toBe(72.0);
});

it('keeps one document\'s review out of another\'s', function (): void {
    expect((new ReviewExport($this->invoice, 'delivery_note'))->toMarkdown())
        ->toContain('0 mark(s)');
});

it('exports from the command line as markdown', function (): void {
    $this->artisan('pindle:export', [
        'model' => Invoice::class,
        'id' => (string) $this->invoice->id,
    ])
        ->expectsOutputToContain('Review of Invoice')
        ->assertSuccessful();
});

it('exports as json when asked', function (): void {
    $this->artisan('pindle:export', [
        'model' => Invoice::class,
        'id' => (string) $this->invoice->id,
        '--format' => 'json',
    ])
        ->expectsOutputToContain('"document_key": "default"')
        ->assertSuccessful();
});

it('writes to a file when given somewhere to put it', function (): void {
    $path = sys_get_temp_dir().'/pindle-review-'.uniqid().'.md';

    $this->artisan('pindle:export', [
        'model' => Invoice::class,
        'id' => (string) $this->invoice->id,
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path))->toContain('Review of Invoice');

    unlink($path);
});

it('takes a morph alias as readily as a class name', function (): void {
    Relation::morphMap(['invoice' => Invoice::class]);

    $this->artisan('pindle:export', [
        'model' => 'invoice',
        'id' => (string) $this->invoice->id,
    ])->assertSuccessful();
});

it('refuses a model that names nothing real', function (): void {
    $this->artisan('pindle:export', ['model' => 'App\\Models\\Nope', 'id' => '1'])
        ->expectsOutputToContain('No such record.')
        ->assertFailed();
});

it('refuses a record that is not there', function (): void {
    $this->artisan('pindle:export', ['model' => Invoice::class, 'id' => '999999'])
        ->assertFailed();
});

it('refuses a format it cannot write', function (): void {
    $this->artisan('pindle:export', [
        'model' => Invoice::class,
        'id' => (string) $this->invoice->id,
        '--format' => 'pdf',
    ])
        ->expectsOutputToContain('must be md or json')
        ->assertFailed();
});
