<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pindle\Policies\AnnotationPolicy;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->policy = app(AnnotationPolicy::class);
    $this->invoice = Invoice::query()->create(['tenant_id' => 1, 'pdf_path' => 'invoices/1.pdf']);
    $this->insider = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
    $this->outsider = User::query()->create(['name' => 'Stranger', 'tenant_id' => 2]);
});

it('lets somebody who can see the document read what is written on it', function (): void {
    expect($this->policy->viewAny($this->insider, $this->invoice))->toBeTrue()
        ->and($this->policy->viewAny($this->outsider, $this->invoice))->toBeFalse();
});

it('lets somebody who can edit the document write on it', function (): void {
    expect($this->policy->create($this->insider, $this->invoice))->toBeTrue()
        ->and($this->policy->update($this->insider, $this->invoice))->toBeTrue()
        ->and($this->policy->delete($this->insider, $this->invoice))->toBeTrue()
        ->and($this->policy->resolve($this->insider, $this->invoice))->toBeTrue();
});

it('refuses every write to somebody from another tenant', function (): void {
    expect($this->policy->create($this->outsider, $this->invoice))->toBeFalse()
        ->and($this->policy->update($this->outsider, $this->invoice))->toBeFalse()
        ->and($this->policy->delete($this->outsider, $this->invoice))->toBeFalse()
        ->and($this->policy->resolve($this->outsider, $this->invoice))->toBeFalse();
});

it('separates reading from writing where the application does', function (): void {
    $auditor = User::query()->create(['name' => 'Auditor', 'tenant_id' => 1]);

    expect($this->policy->viewAny($auditor, $this->invoice))->toBeTrue()
        ->and($this->policy->create($auditor, $this->invoice))->toBeFalse();
});

it('asks the ability the configuration names rather than one of its own', function (): void {
    Gate::define('annotate', fn (User $user, Invoice $invoice): bool => $user->name === 'Reviewer');

    config()->set('pindle.policy.abilities.create', 'annotate');

    expect($this->policy->create($this->insider, $this->invoice))->toBeTrue()
        ->and($this->policy->create($this->outsider, $this->invoice))->toBeFalse();
});

it('refuses rather than defaults when an ability is unmapped', function (): void {
    config()->set('pindle.policy.abilities.create', false);

    expect($this->policy->create($this->insider, $this->invoice))->toBeFalse();

    config()->set('pindle.policy.abilities.viewAny', '');

    expect($this->policy->viewAny($this->insider, $this->invoice))->toBeFalse();
});

it('refuses a guest, since the application\'s policy takes a user', function (): void {
    expect($this->policy->viewAny(null, $this->invoice))->toBeFalse()
        ->and($this->policy->create(null, $this->invoice))->toBeFalse();
});
