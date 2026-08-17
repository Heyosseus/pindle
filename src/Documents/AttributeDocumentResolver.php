<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pindle\Contracts\DocumentResolver;

/**
 * The default: read the path out of the attribute the model named.
 *
 * This is the whole of the five-minute install. A model that already stores its
 * PDF path needs the trait and nothing else; `$pindleDocuments` exists only
 * because a model may hold more than one document.
 */
final readonly class AttributeDocumentResolver implements DocumentResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(Model $model, string $key): ?PindleDocument
    {
        $attribute = DocumentMap::for($model)[$key] ?? null;

        if ($attribute === null) {
            return null;
        }

        $path = $model->getAttribute($attribute);

        // An unset path is a document that has not been uploaded yet, which is a
        // state every application has and none of them considers an error.
        if (! is_string($path) || $path === '') {
            return null;
        }

        return new PindleDocument(
            disk: $this->disk(),
            path: $path,
            key: $key,
        );
    }

    private function disk(): string
    {
        $disk = $this->config->get('pindle.documents.disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
