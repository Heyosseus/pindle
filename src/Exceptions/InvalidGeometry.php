<?php

declare(strict_types=1);

namespace Pindle\Exceptions;

use InvalidArgumentException;

/**
 * Geometry that cannot describe a place on a page.
 *
 * Thrown by the value objects rather than by validation, so that geometry built
 * in application code -- a seeder, an import, a job -- is held to the same
 * standard as geometry that arrived over HTTP.
 */
final class InvalidGeometry extends InvalidArgumentException
{
    public static function nonFiniteOrdinate(): self
    {
        return new self('A rectangle ordinate must be a finite number.');
    }

    public static function missingOrdinate(string $key): self
    {
        return new self(sprintf('A rectangle needs a numeric "%s" ordinate.', $key));
    }

    public static function notARectangle(): self
    {
        return new self('Each anchor must be an object with x1, y1, x2 and y2.');
    }
}
