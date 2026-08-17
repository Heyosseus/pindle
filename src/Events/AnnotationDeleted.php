<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;

/**
 * An annotation was taken down.
 *
 * Soft-deleted, so the annotation this carries is still readable. Whatever a
 * listener wants to record about the removal, it can still read what was removed.
 */
final readonly class AnnotationDeleted
{
    use Dispatchable;

    public function __construct(
        public Annotation $annotation,
        public ?Authenticatable $actor = null,
    ) {}
}
