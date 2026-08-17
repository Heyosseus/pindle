<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;

/**
 * Somebody drew on a document.
 *
 * These five events are the reason Pindle ships no approval workflow, no
 * notification and no status machine. Every application's version of "the
 * finance team must sign off once legal has commented" is different enough that
 * a built-in one would be wrong for everyone; a hook that fires at the moment it
 * happens is right for all of them.
 */
final readonly class AnnotationCreated
{
    use Dispatchable;

    public function __construct(
        public Annotation $annotation,
        public ?Authenticatable $actor = null,
    ) {}
}
