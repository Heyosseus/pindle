<?php

declare(strict_types=1);

namespace Pindle\Http\Controllers;

use function abort;

use Illuminate\Http\Request;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\DocumentSignature;
use Pindle\Documents\DocumentStream;
use Pindle\Documents\PindleDocument;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bytes, to whoever the link was minted for and still may see them.
 *
 * The signature is never the authorisation. It says which document is being
 * asked for and who asked; the policy is then asked afresh, on this request,
 * about the model that owns it. A link minted an hour ago for somebody who has
 * since been removed from the project is cryptographically perfect and still
 * answers 403 -- which is the entire reason the payload is re-authorised rather
 * than trusted.
 */
final class DocumentController
{
    use ResolvesAnnotatable;

    public function __invoke(Request $request, string $document): Response
    {
        $payload = DocumentSignature::decode($document);

        if (! $payload instanceof DocumentSignature) {
            abort(404);
        }

        // A signed URL is not a bearer token. It was minted for one viewer, and
        // passing it to somebody else does not carry the access with it.
        if (! $payload->belongsTo($request->user())) {
            abort(403);
        }

        $annotatable = $payload->annotatable();

        if (! $annotatable instanceof \Illuminate\Database\Eloquent\Model) {
            abort(404);
        }

        $this->authorizeDocument('viewAny', $annotatable);

        $resolved = app(DocumentResolver::class)->resolve($annotatable, $payload->key);

        if (! $resolved instanceof PindleDocument || ! $resolved->exists()) {
            abort(404);
        }

        return (new DocumentStream($resolved))->response($request->header('Range'));
    }
}
