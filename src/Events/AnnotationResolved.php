<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;

/**
 * A point raised on a document was marked settled.
 *
 * Fires on the transition only, not on every save of a resolved annotation --
 * a listener that emails the author should not email them again because
 * somebody nudged the highlight.
 */
final readonly class AnnotationResolved
{
    use Dispatchable;

    public function __construct(
        public Annotation $annotation,
        public ?Authenticatable $actor = null,
    ) {}
}
