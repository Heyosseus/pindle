<?php

declare(strict_types=1);

use Pindle\Enums\AnnotationType;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\User;

/*
 * The factories exist for applications rather than for this suite.
 *
 * Anyone testing an approval flow needs an invoice with an open objection on it,
 * and without these that is forty lines of morph columns and hashing in every
 * project that installs the package -- with one subtle way to get it wrong that
 * makes every annotation silently an orphan and the flow under test never see an
 * open mark at all.
 */

it('anchors an annotation to the document as it stands', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = Annotation::factory()->on($invoice)->create();

    expect($annotation->annotatable_type)->toBe($invoice->getMorphClass())
        ->and($annotation->annotatable_id)->toBe((string) $invoice->getKey())
        ->and($annotation->document_key)->toBe('default')
        ->and($annotation->isOrphanedFrom($invoice->pindleDocument()))->toBeFalse();
});

it('anchors to whichever document it was pointed at', function (): void {
    $invoice = invoiceWithDocument();

    Storage::disk('documents')->put('notes/1.pdf', '%PDF delivery note');
    $invoice->update(['delivery_pdf_path' => 'notes/1.pdf']);

    $annotation = Annotation::factory()->on($invoice, 'delivery_note')->create();

    expect($annotation->document_key)->toBe('delivery_note')
        ->and($annotation->isOrphanedFrom($invoice->pindleDocument('delivery_note')))->toBeFalse();
});

it('anchors to a model with no document at all', function (): void {
    // An ordinary thing to write a test about, and it should not require
    // putting a PDF on a disk first.
    $invoice = Invoice::query()->create([]);

    expect(Annotation::factory()->on($invoice)->create()->document_hash)->toHaveLength(64);
});

it('makes an orphan without needing the document replaced', function (): void {
    $invoice = invoiceWithDocument();

    $annotation = Annotation::factory()->on($invoice)->orphaned()->create();

    expect($annotation->isOrphanedFrom($invoice->pindleDocument()))->toBeTrue();
});

it('attributes an annotation to whoever drew it', function (): void {
    $reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    $annotation = Annotation::factory()->by($reviewer)->create();

    expect($annotation->author_type)->toBe($reviewer->getMorphClass())
        ->and($annotation->author_id)->toBe((string) $reviewer->getKey());
});

it('makes a resolved annotation, with and without a resolver', function (): void {
    $reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);

    expect(Annotation::factory()->resolved($reviewer)->create()->resolved_by_id)
        ->toBe((string) $reviewer->getKey())
        ->and(Annotation::factory()->resolved()->create())
        ->isResolved()->toBeTrue();
});

it('places a mark on a page, of a type, over particular words', function (): void {
    $annotation = Annotation::factory()->onPage(4)->highlighting('the indemnity clause')->create();

    expect($annotation->page)->toBe(4)
        ->and($annotation->type)->toBe(AnnotationType::Highlight)
        ->and($annotation->text_snippet)->toBe('the indemnity clause')
        ->and(Annotation::factory()->ofType(AnnotationType::Note)->create()->type)
        ->toBe(AnnotationType::Note);
});

it('leaves an unattached annotation obviously unattached', function (): void {
    // Rather than pointing at whatever record happens to share the id, so a
    // test that forgot to call on() fails instead of quietly passing.
    expect(Annotation::factory()->create()->annotatable_type)->toBe('pindle-unattached');
});

it('makes a comment on an annotation, attributed', function (): void {
    $reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
    $annotation = Annotation::factory()->create();

    $comment = Comment::factory()->on($annotation)->by($reviewer)->saying('Still disputed.')->create();

    expect($comment->annotation_id)->toBe($annotation->id)
        ->and($comment->author_id)->toBe((string) $reviewer->getKey())
        ->and($comment->body)->toBe('Still disputed.')
        ->and($comment->isReply())->toBeFalse();
});

it('makes a comment its own annotation when it was given none', function (): void {
    expect(Comment::factory()->create()->annotation()->firstOrFail())
        ->toBeInstanceOf(Annotation::class);
});

it('keeps replies one level deep, as the endpoint does', function (): void {
    $annotation = Annotation::factory()->create();

    $first = Comment::factory()->on($annotation)->create();
    $reply = Comment::factory()->replyTo($first)->create();

    // A reply to a reply attaches to the same parent rather than nesting a
    // third time, so a fixture cannot build a shape the API would refuse.
    $second = Comment::factory()->replyTo($reply)->create();

    expect($reply->parent_id)->toBe($first->id)
        ->and($second->parent_id)->toBe($first->id)
        ->and($second->annotation_id)->toBe($annotation->id);
});
