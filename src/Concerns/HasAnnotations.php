<?php

declare(strict_types=1);

namespace Pindle\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\DocumentMap;
use Pindle\Documents\PindleDocument;
use Pindle\Models\Annotation;
use Pindle\Pindle;

/**
 * Put this on the model the PDF belongs to.
 *
 * ```php
 * class Invoice extends Model
 * {
 *     use HasAnnotations;
 *
 *     protected array $pindleDocuments = [
 *         'default'       => 'pdf_path',
 *         'delivery_note' => 'delivery_pdf_path',
 *     ];
 * }
 * ```
 *
 * Declare nothing and the model is assumed to hold one PDF on `pdf_path`, which
 * is the conventional column and the shortest path to a working viewer.
 *
 * Note what the trait deliberately does not add: no tenant column, no ownership
 * column, no scope of its own. An annotation is reachable only through the model
 * it is written on, so whatever already decides who may see this invoice already
 * decides who may see what is written on it.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasAnnotations
{
    /**
     * Every annotation on every document of this model.
     *
     * @return MorphMany<Annotation, $this>
     */
    public function annotations(): MorphMany
    {
        return $this->morphMany(Pindle::annotationModel(), 'annotatable');
    }

    /**
     * The annotations on one of its documents.
     *
     * @return MorphMany<Annotation, $this>
     */
    public function annotationsFor(string $key = 'default'): MorphMany
    {
        return $this->annotations()->where('document_key', $key);
    }

    /**
     * The PDF itself, or null when this model has no document under that key.
     *
     * Null is an ordinary answer rather than a failure: a model may hold a
     * delivery note only once one has been issued.
     */
    public function pindleDocument(string $key = 'default'): ?PindleDocument
    {
        return app(DocumentResolver::class)->resolve($this, $key);
    }

    /**
     * The document keys this model declares.
     *
     * @return list<string>
     */
    public function pindleDocumentKeys(): array
    {
        return array_keys(DocumentMap::for($this));
    }
}
