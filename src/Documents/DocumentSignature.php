<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\URL;
use JsonException;
use Pindle\Support\Key;

/**
 * What a document URL says about itself: which model, which document, and who it
 * was minted for.
 *
 * The token is not a secret and is not treated as one. Laravel's own expiring
 * signature is what makes it untamperable; this is only the payload underneath.
 * Crucially, it is also not an authorisation -- the controller re-asks the policy
 * on every request, so a link minted while somebody had access stops working the
 * moment they lose it, even though the signature is still perfectly valid.
 */
final readonly class DocumentSignature
{
    public function __construct(
        public string $morph,
        public string $id,
        public string $key,
        public ?string $userId,
    ) {}

    /**
     * Mint the payload for one document of one model, for one viewer.
     */
    public static function for(Model $annotatable, string $key, ?Authenticatable $user): self
    {
        $userId = $user?->getAuthIdentifier();

        return new self(
            morph: $annotatable->getMorphClass(),
            id: Key::of($annotatable),
            key: $key,
            userId: is_int($userId) || is_string($userId) ? (string) $userId : null,
        );
    }

    /**
     * The full signed, expiring URL a viewer fetches bytes from.
     */
    public static function url(Model $annotatable, string $key, ?Authenticatable $user): string
    {
        $ttl = app(Repository::class)->get('pindle.documents.url_ttl');

        return URL::temporarySignedRoute(
            'pindle.documents.show',
            now()->addSeconds(is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 300),
            ['document' => self::for($annotatable, $key, $user)->encode()],
        );
    }

    public function encode(): string
    {
        $payload = json_encode([
            't' => $this->morph,
            'i' => $this->id,
            'k' => $this->key,
            'u' => $this->userId,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * The payload back out of a token, or null when it is not one.
     */
    public static function decode(string $token): ?self
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $morph = $payload['t'] ?? null;
        $id = $payload['i'] ?? null;
        $key = $payload['k'] ?? null;
        $userId = $payload['u'] ?? null;

        if (! is_string($morph) || ! is_string($id) || ! is_string($key)) {
            return null;
        }

        return new self($morph, $id, $key, is_string($userId) ? $userId : null);
    }

    /**
     * The model this points at, or null when it names nothing real.
     *
     * The morph is resolved through Laravel's own map rather than by treating the
     * token as a class name -- otherwise the token would be an instruction to
     * instantiate whatever class it liked, which is a remote code path with extra
     * steps. A morph alias that was never registered resolves to nothing.
     */
    public function annotatable(): ?Model
    {
        $class = Relation::getMorphedModel($this->morph) ?? $this->morph;

        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($this->id);
    }

    /**
     * Whether this link was minted for the person now holding it.
     *
     * A signed URL that outlives the session it was made in should not become a
     * bearer token that anyone can pass around.
     */
    public function belongsTo(?Authenticatable $user): bool
    {
        $identifier = $user?->getAuthIdentifier();

        $current = is_int($identifier) || is_string($identifier) ? (string) $identifier : null;

        return $this->userId === $current;
    }
}
