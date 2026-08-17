<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Pindle\Events\AnnotationCreated;
use Pindle\Events\AnnotationDeleted;
use Pindle\Events\AnnotationResolved;
use Pindle\Events\AnnotationUpdated;
use Pindle\Events\CommentPosted;
use Pindle\Models\Comment;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Event::fake([
        AnnotationCreated::class,
        AnnotationUpdated::class,
        AnnotationDeleted::class,
        AnnotationResolved::class,
        CommentPosted::class,
    ]);
});

it('announces an annotation as it is drawn', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Event::assertDispatched(
        AnnotationCreated::class,
        fn (AnnotationCreated $event): bool => $event->annotation->is($annotation),
    );
});

it('names whoever was acting', function (): void {
    $user = User::query()->create(['name' => 'Reviewer']);

    $this->actingAs($user);

    annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    Event::assertDispatched(
        AnnotationCreated::class,
        fn (AnnotationCreated $event): bool => $event->actor?->getAuthIdentifier() === $user->id,
    );
});

it('announces a change to the geometry', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['rects' => [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]]);

    Event::assertDispatched(AnnotationUpdated::class);
    Event::assertNotDispatched(AnnotationResolved::class);
});

it('announces resolution as its own event, and only on the way in', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['resolved_at' => now()]);

    Event::assertDispatched(AnnotationResolved::class);
    Event::assertNotDispatched(AnnotationUpdated::class);

    // Nudging an already-resolved annotation is an update, not a second resolution.
    $annotation->update(['color' => '#fde047']);

    Event::assertDispatchedTimes(AnnotationResolved::class, 1);
    Event::assertDispatched(AnnotationUpdated::class);
});

it('treats reopening as an ordinary update', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->update(['resolved_at' => now()]);
    $annotation->update(['resolved_at' => null]);

    Event::assertDispatchedTimes(AnnotationResolved::class, 1);
    Event::assertDispatchedTimes(AnnotationUpdated::class, 1);
});

it('announces a deletion, but not the pruning of one announced months ago', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->delete();

    Event::assertDispatchedTimes(AnnotationDeleted::class, 1);

    $annotation->forceDelete();

    Event::assertDispatchedTimes(AnnotationDeleted::class, 1);
});

it('announces a comment together with the annotation it hangs off', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $comment = Comment::query()->create([
        'annotation_id' => $annotation->id,
        'author_type' => 'user',
        'author_id' => '1',
        'body' => 'This total does not match the purchase order.',
    ]);

    Event::assertDispatched(
        CommentPosted::class,
        fn (CommentPosted $event): bool => $event->comment->is($comment)
            && $event->annotation->is($annotation),
    );
});
