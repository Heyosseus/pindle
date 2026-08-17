<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

it('discards a middleware stack that is not a list rather than crashing on it', function (): void {
    $route = Route::getRoutes()->getByName('pindle.annotations.index');

    expect($route?->gatherMiddleware())->toBe([]);
});

it('still lets the policy decide when the stack was thrown away', function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $invoice = invoiceWithDocument();
    $invoice->update(['tenant_id' => 1]);

    // A mangled middleware list must not become an open door: authorisation was
    // never the middleware's job here, and it still is not.
    $this->getJson(listUrl($invoice))->assertForbidden();

    $stranger = User::query()->create(['name' => 'Stranger', 'tenant_id' => 2]);

    $this->actingAs($stranger)->getJson(listUrl($invoice))->assertForbidden();
});
