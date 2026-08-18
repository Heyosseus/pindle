<?php

declare(strict_types=1);

use Pindle\Events\AnnotationResolved;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Tests\Fixtures\User;

/*
 * Resolving from code, and asking the annotations table questions.
 *
 * The pitch for keeping marks in your own database is that you can query them.
 * That is only true if there is something to query them with, so the scopes here
 * are the pitch rather than a convenience: which objections are open, which no
 * longer point at anything, and which of them mention the indemnity clause.
 */

it('resolves and re-opens from code, not only over http', function (): void {
    $reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
    $annotation = Annotation::factory()->create();

    $annotation->resolve($reviewer);

    expect($annotation->isResolved())->toBeTrue()
        ->and($annotation->resolved_by_id)->toBe((string) $reviewer->getKey());

    $annotation->unresolve();

    expect($annotation->fresh()?->isResolved())->toBeFalse()
        ->and($annotation->fresh()?->resolved_by_id)->toBeNull();
});

it('takes the acting user when told nobody in particular', function (): void {
    $reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    $this->actingAs($reviewer);

    $annotation = Annotation::factory()->create();

    $annotation->resolve();

    expect($annotation->resolved_by_id)->toBe((string) $reviewer->getKey());
});

it('records nobody when nobody is acting', function (): void {
    $annotation = Annotation::factory()->create();

    $annotation->resolve();

    expect($annotation->isResolved())->toBeTrue()
        ->and($annotation->resolved_by_id)->toBeNull();
});

it('raises the same event a reviewer clicking resolve does', function (): void {
    Event::fake([AnnotationResolved::class]);

    // The whole reason resolution lives on the model: an approval job settling
    // an objection should be heard exactly as loudly as somebody clicking.
    Annotation::factory()->create()->resolve();

    Event::assertDispatched(AnnotationResolved::class);
});

it('separates what is open from what is settled', function (): void {
    Annotation::factory()->count(2)->create();
    Annotation::factory()->resolved()->create();

    expect(Annotation::query()->unresolved()->count())->toBe(2)
        ->and(Annotation::query()->resolved()->count())->toBe(1);
});

it('finds the marks that no longer point at the document', function (): void {
    $invoice = invoiceWithDocument();

    Annotation::factory()->on($invoice)->count(2)->create();
    Annotation::factory()->on($invoice)->orphaned()->create();

    $document = $invoice->pindleDocument();

    expect(Annotation::query()->orphanedFrom($document)->count())->toBe(1);
});

it('searches the anchored text and the discussion under it', function (): void {
    $onPage = Annotation::factory()->highlighting('the indemnity clause')->create();
    $inThread = Annotation::factory()->highlighting('something else')->create();
    Annotation::factory()->highlighting('unrelated')->create();

    Comment::factory()->on($inThread)->saying('This contradicts the indemnity elsewhere.')->create();

    $found = Annotation::query()->search('indemnity')->pluck('id')->all();

    expect($found)->toHaveCount(2)
        ->toContain($onPage->id)
        ->toContain($inThread->id);
});

it('treats a blank search as no search at all', function (): void {
    Annotation::factory()->count(3)->create();

    expect(Annotation::query()->search('   ')->count())->toBe(3);
});

it('does not let a wildcard in the term match the whole table', function (): void {
    Annotation::factory()->highlighting('100% of the fee')->create();
    Annotation::factory()->highlighting('a flat fee')->create();

    // Left in, "100%" would match the second row too, and "%" on its own would
    // match every annotation there is.
    expect(Annotation::query()->search('100%')->count())->toBe(1)
        ->and(Annotation::query()->search('%')->count())->toBe(2);
});
