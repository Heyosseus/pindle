<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;

/**
 * Somebody said something on a thread.
 *
 * Carries the annotation as well as the comment, because the thing a listener
 * almost always wants -- which document, whose, which page -- hangs off the
 * annotation rather than off the comment.
 */
final readonly class CommentPosted
{
    use Dispatchable;

    public function __construct(
        public Comment $comment,
        public Annotation $annotation,
        public ?Authenticatable $actor = null,
    ) {}
}
