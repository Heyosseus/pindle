<?php

declare(strict_types=1);

namespace Pindle\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A model's key as the string the morph columns hold.
 *
 * Pindle's morph ids are strings so that an application keyed by ULID, UUID or
 * integer all work without being asked which. That means every write and every
 * lookup has to agree on the conversion, and one place to do it is how they stay
 * agreed.
 *
 * @internal
 */
final class Key
{
    public static function of(Model $model): string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
