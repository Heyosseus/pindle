<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;

/** An annotation's geometry, colour or metadata changed. */
final readonly class AnnotationUpdated
{
    use Dispatchable;

    public function __construct(
        public Annotation $annotation,
        public ?Authenticatable $actor = null,
    ) {}
}
