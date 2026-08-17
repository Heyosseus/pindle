<?php

declare(strict_types=1);

namespace Pindle\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Whoever is acting, as the morph pair the tables store.
 *
 * A morph rather than a foreign key to users, because "who annotated this" is
 * not always a user: an application with separate customer and staff guards, or
 * one that lets a service account leave notes, has two authors of different
 * kinds on the same document. A `user_id` column would have forced one of them
 * to lie.
 *
 * @internal
 */
final readonly class Author
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    /**
     * The current actor, or an anonymous one on an unauthenticated route.
     */
    public static function current(): self
    {
        return self::of(Auth::user());
    }

    public static function of(?Authenticatable $user): self
    {
        if (! $user instanceof Authenticatable) {
            return new self('guest', '');
        }

        $identifier = $user->getAuthIdentifier();

        return new self(
            $user instanceof Model ? $user->getMorphClass() : $user::class,
            is_int($identifier) || is_string($identifier) ? (string) $identifier : '',
        );
    }
}
