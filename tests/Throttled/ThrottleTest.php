<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

/*
 * Writing is rate limited; reading is not.
 *
 * The asymmetry is the point. One page of a large PDF is a burst of range
 * requests -- PDFium asks for whatever byte spans it needs and asks again on
 * every scroll -- so a limit tight enough to stop somebody posting ten thousand
 * comments would stop everybody reading, and a limit loose enough not to would
 * not be a limit at all.
 *
 * The application these run against boots with a limit of three a minute; see
 * ThrottledTestCase.
 */

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    $this->actingAs($this->reviewer);
});

it('turns writes away once the limit is reached', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
            ->assertCreated();
    }

    $this->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
        ->assertStatus(429);
});

it('counts every write endpoint against the same limit', function (): void {
    $annotation = annotate($this->invoice, [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]]);

    $this->postJson(route('pindle.comments.store', $annotation), ['body' => 'One'])->assertCreated();
    $this->postJson(route('pindle.comments.store', $annotation), ['body' => 'Two'])->assertCreated();
    $this->postJson(route('pindle.comments.store', $annotation), ['body' => 'Three'])->assertCreated();

    // A different route, but the same person writing, so the same bucket.
    $this->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
        ->assertStatus(429);
});

it('leaves reading alone however much of it there is', function (): void {
    foreach (range(1, 10) as $ignored) {
        $this->getJson(listUrl($this->invoice))->assertOk();
    }
});
