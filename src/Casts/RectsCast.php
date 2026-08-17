<?php

declare(strict_types=1);

namespace Pindle\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Pindle\Geometry\Rects;

/**
 * The `rects` column, as a {@see Rects} on the way out and as JSON on the way in.
 *
 * Anchors are the one thing in Pindle that must survive a round trip untouched,
 * so they are never handled as bare arrays on a model: everything that reads
 * them gets the value object, and everything that writes them goes through its
 * normalisation.
 *
 * @implements CastsAttributes<Rects, Rects|iterable<array-key, mixed>>
 */
final class RectsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Rects
    {
        if (! is_string($value) || $value === '') {
            return Rects::make([]);
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? Rects::fromArray($decoded) : Rects::make([]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $rects = $value instanceof Rects
            ? $value
            : Rects::fromArray(is_iterable($value) ? iterator_to_array($value) : []);

        // JSON_PRESERVE_ZERO_FRACTION so 72.0 survives as 72.0 rather than 72.
        // The value is a float either way once decoded, but a column that reads
        // back differently from what was written invites the belief that
        // something rounded it.
        return [$key => json_encode($rects->toArray(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)];
    }
}
