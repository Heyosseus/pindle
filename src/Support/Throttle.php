<?php

declare(strict_types=1);

namespace Pindle\Support;

/**
 * The rate limit on Pindle's write endpoints, as configured.
 *
 * A tiny class rather than three lines in the routes file, because the routes
 * file is loaded once at boot and cannot be asserted on afterwards -- and
 * "writes are throttled and reads are not" is a claim worth a test.
 *
 * @internal
 */
final class Throttle
{
    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        $limit = config('pindle.routes.throttle');

        // A named limiter ("pindle") or a rate ("60,1") both work, because both
        // are what `throttle:` already accepts. Null or blank means the
        // application would rather do its own limiting, and Pindle adds nothing.
        if (! is_string($limit) || trim($limit) === '') {
            return [];
        }

        return ['throttle:'.trim($limit)];
    }
}
