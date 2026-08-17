<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pindle\Documents\DocumentSignature;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

/*
 * Cross-tenant denial, across every one of the eight routes.
 *
 * This file is not optional and is not a formality. Pindle's entire tenancy
 * story is "an annotation is reachable only through a model you can already
 * see" -- there is no tenant column to fall back on, so if any endpoint reaches
 * an annotation without going through the owning model's policy, the isolation
 * is not weakened, it is absent. Every route is enumerated here for that reason:
 * a route added later without a policy check should fail a test, not ship.
 *
 * Tenant A's reviewer holds an invoice, an annotation and a comment. Tenant B's
 * stranger holds nothing and may reach nothing.
 */
beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->annotation = annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    $this->comment = comment($this->annotation, 'Tenant A said this.');

    $this->owner = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
    $this->stranger = User::query()->create(['name' => 'Stranger', 'tenant_id' => 2]);
});

it('refuses to list another tenant\'s annotations', function (): void {
    $this->actingAs($this->stranger)
        ->getJson(listUrl($this->invoice))
        ->assertForbidden();
});

it('refuses to write on another tenant\'s document', function (): void {
    $this->actingAs($this->stranger)
        ->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
        ->assertForbidden();

    expect(Annotation::query()->count())->toBe(1);
});

it('refuses to move another tenant\'s annotation', function (): void {
    $this->actingAs($this->stranger)
        ->patchJson(route('pindle.annotations.update', $this->annotation->id), [
            'rects' => [['x1' => 9.0, 'y1' => 9.0, 'x2' => 9.0, 'y2' => 9.0]],
        ])
        ->assertForbidden();

    expect($this->annotation->fresh()?->rects->toArray())
        ->toBe([['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
});

it('refuses to settle another tenant\'s annotation', function (): void {
    $this->actingAs($this->stranger)
        ->patchJson(route('pindle.annotations.update', $this->annotation->id), ['resolved' => true])
        ->assertForbidden();

    expect($this->annotation->fresh()?->isResolved())->toBeFalse();
});

it('refuses to delete another tenant\'s annotation', function (): void {
    $this->actingAs($this->stranger)
        ->deleteJson(route('pindle.annotations.destroy', $this->annotation->id))
        ->assertForbidden();

    expect(Annotation::query()->count())->toBe(1);
});

it('refuses to comment on another tenant\'s annotation', function (): void {
    $this->actingAs($this->stranger)
        ->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => 'Intruding.'])
        ->assertForbidden();

    expect(Comment::query()->count())->toBe(1);
});

it('refuses to edit another tenant\'s comment', function (): void {
    $this->actingAs($this->stranger)
        ->patchJson(route('pindle.comments.update', $this->comment->id), ['body' => 'Rewritten.'])
        ->assertForbidden();

    expect($this->comment->fresh()?->body)->toBe('Tenant A said this.');
});

it('refuses to delete another tenant\'s comment', function (): void {
    $this->actingAs($this->stranger)
        ->deleteJson(route('pindle.comments.destroy', $this->comment->id))
        ->assertForbidden();

    expect(Comment::query()->count())->toBe(1);
});

it('refuses to stream another tenant\'s document', function (): void {
    // Minted by the stranger, for the stranger: the signature is theirs and
    // valid. It is the policy that stops them, which is the whole design.
    $this->actingAs($this->stranger)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->stranger))
        ->assertForbidden();
});

it('refuses to stream a document on a link lifted from another tenant', function (): void {
    $url = DocumentSignature::url($this->invoice, 'default', $this->owner);

    $this->actingAs($this->stranger)->get($url)->assertForbidden();
});

it('refuses a guest on every route', function (): void {
    $this->getJson(listUrl($this->invoice))->assertForbidden();
    $this->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))->assertForbidden();
    $this->patchJson(route('pindle.annotations.update', $this->annotation->id), ['color' => '#fde047'])->assertForbidden();
    $this->deleteJson(route('pindle.annotations.destroy', $this->annotation->id))->assertForbidden();
    $this->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => 'Hello.'])->assertForbidden();
    $this->patchJson(route('pindle.comments.update', $this->comment->id), ['body' => 'Hello.'])->assertForbidden();
    $this->deleteJson(route('pindle.comments.destroy', $this->comment->id))->assertForbidden();
    $this->get(DocumentSignature::url($this->invoice, 'default', null))->assertForbidden();
});

it('lets the tenant who owns the document do all of it', function (): void {
    $this->actingAs($this->owner)->getJson(listUrl($this->invoice))->assertOk();
    $this->actingAs($this->owner)->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))->assertCreated();
    $this->actingAs($this->owner)->patchJson(route('pindle.annotations.update', $this->annotation->id), ['color' => '#fde047'])->assertOk();
    $this->actingAs($this->owner)->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => 'Fine.'])->assertCreated();
    $this->actingAs($this->owner)->patchJson(route('pindle.comments.update', $this->comment->id), ['body' => 'Edited.'])->assertOk();
    $this->actingAs($this->owner)->get(DocumentSignature::url($this->invoice, 'default', $this->owner))->assertOk();
    $this->actingAs($this->owner)->deleteJson(route('pindle.comments.destroy', $this->comment->id))->assertNoContent();
    $this->actingAs($this->owner)->deleteJson(route('pindle.annotations.destroy', $this->annotation->id))->assertNoContent();
});

it('ships an authenticated middleware stack by default', function (): void {
    // The suite runs without 'auth' so that a guest reaches the policy rather
    // than a login redirect. What an application actually installs is stricter,
    // and this is where that is pinned down.
    expect(config('pindle.routes.middleware'))->toBe(['web'])
        ->and(require __DIR__.'/../../../config/pindle.php')
        ->toHaveKey('routes.middleware', ['web', 'auth']);
});
