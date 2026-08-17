<?php

declare(strict_types=1);

namespace Pindle\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\DocumentMap;
use Pindle\Documents\PindleDocument;
use Pindle\Models\Annotation;
use Pindle\Pindle;
use Pindle\Review\ReviewSummary;

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

    /**
     * What state this document's review is in: how much is open, how much is
     * settled, and how much no longer points at the right place.
     *
     * This is what makes storing annotations in your own database worth
     * something. A badge on a table, a guard on an approval button, a nightly
     * report -- all of them are this one call, and none of them needs the
     * viewer to be on screen.
     */
    public function pindleReview(string $key = 'default'): ReviewSummary
    {
        return ReviewSummary::for($this, $key);
    }

    /**
     * The review of every document this model carries, keyed by document.
     *
     * @return array<string, ReviewSummary>
     */
    public function pindleReviews(): array
    {
        $reviews = [];

        foreach ($this->pindleDocumentKeys() as $key) {
            $reviews[$key] = $this->pindleReview($key);
        }

        return $reviews;
    }
}
