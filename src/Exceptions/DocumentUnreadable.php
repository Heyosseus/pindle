<?php

declare(strict_types=1);

namespace Pindle\Exceptions;

use RuntimeException;

/**
 * A document that resolved to a path the disk cannot open.
 *
 * Distinct from "this model has no such document", which is a null resolution
 * and not an error: a model may legitimately have no delivery note. This is the
 * other case -- the model says the file is there and the disk disagrees -- and
 * it is worth a stack trace rather than an empty viewer.
 */
final class DocumentUnreadable extends RuntimeException
{
    public static function at(string $disk, string $path): self
    {
        return new self(sprintf('Pindle could not read [%s] from the [%s] disk.', $path, $disk));
    }
}
