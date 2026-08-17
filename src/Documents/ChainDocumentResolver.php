<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Illuminate\Database\Eloquent\Model;
use Pindle\Contracts\DocumentResolver;

/**
 * Ask each resolver in turn and take the first document any of them finds.
 *
 * What this buys is that installing Media Library does not change where Pindle
 * looks for the documents it was already finding. The attribute resolver is
 * asked first and the media one only answers for keys it did not cover, so a
 * model can hold its invoice on a column and its delivery note in a collection
 * without either resolver knowing about the other.
 */
final readonly class ChainDocumentResolver implements DocumentResolver
{
    /**
     * @param  list<DocumentResolver>  $resolvers
     */
    public function __construct(private array $resolvers) {}

    public function resolve(Model $model, string $key): ?PindleDocument
    {
        foreach ($this->resolvers as $resolver) {
            $document = $resolver->resolve($model, $key);

            if ($document instanceof PindleDocument) {
                return $document;
            }
        }

        return null;
    }
}
