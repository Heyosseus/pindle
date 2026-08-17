<?php

declare(strict_types=1);

use Pindle\Models\Comment;

it('hangs a thread off an annotation, oldest first', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $first = comment($annotation, 'This total does not match the purchase order.');
    $second = comment($annotation, 'Agreed, the freight line is duplicated.');

    expect($annotation->comments()->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('threads one level of replies', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $root = comment($annotation, 'This total does not match.');
    $reply = comment($annotation, 'Corrected in revision B.', $root);

    expect($reply->isReply())->toBeTrue()
        ->and($root->isReply())->toBeFalse()
        ->and($root->replies()->pluck('id')->all())->toBe([$reply->id])
        ->and($reply->parent()->first()?->id)->toBe($root->id);
});

it('attaches a reply to a reply to the same parent rather than nesting again', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $root = comment($annotation, 'This total does not match.');
    $reply = comment($annotation, 'Corrected in revision B.');

    expect($root->threadRoot()->id)->toBe($root->id)
        ->and(comment($annotation, 'Thanks.', $reply->threadRoot())->parent_id)->toBe($reply->id);

    $deep = comment($annotation, 'Confirmed.', $reply);

    expect($deep->threadRoot()->id)->toBe($reply->id);
});

it('keeps a deleted comment as an audit trail', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    comment($annotation, 'Withdrawn.')->delete();

    expect(Comment::query()->count())->toBe(0)
        ->and(Comment::query()->withTrashed()->count())->toBe(1);
});

it('takes the thread down with the annotation it belonged to', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    comment($annotation, 'This total does not match.');

    $annotation->forceDelete();

    expect(Comment::query()->withTrashed()->count())->toBe(0);
});

it('finds its way back to the annotation it is about', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    expect(comment($annotation, 'Noted.')->annotation()->first()?->id)->toBe($annotation->id);
});
