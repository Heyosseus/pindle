<?php

declare(strict_types=1);

namespace Pindle\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Pindle\Models\Annotation;

/**
 * An orphaned annotation was moved onto the document that replaced the one it
 * was drawn on.
 *
 * Its own event rather than an update, because it is the one change that
 * deliberately rewrites `document_hash` -- the field everything else treats as
 * evidence. An application that audits its documents will want to record who
 * decided that this objection to clause 4 is still an objection to clause 4.
 */
final readonly class AnnotationReanchored
{
    use Dispatchable;

    public function __construct(
        public Annotation $annotation,
        public string $previousHash,
        public ?Authenticatable $actor = null,
    ) {}
}
