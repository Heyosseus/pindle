<?php

declare(strict_types=1);

namespace Pindle\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pindle\Documents\PindleDocument;

/**
 * How Pindle finds the PDF behind a model.
 *
 * Bind your own to serve documents from somewhere the default cannot reach -- a
 * signed S3 path assembled at runtime, a document service, a column that holds
 * an id rather than a path. Returning null means "this model has no document
 * under that key", which is an ordinary answer and not a failure.
 */
interface DocumentResolver
{
    public function resolve(Model $model, string $key): ?PindleDocument;
}
