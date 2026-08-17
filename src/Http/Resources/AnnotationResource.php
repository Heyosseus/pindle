<?php

declare(strict_types=1);

namespace Pindle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pindle\Documents\PindleDocument;
use Pindle\Models\Annotation;

/**
 * One annotation, in the shape the viewer and any other client reads.
 *
 * `orphaned` is the field worth explaining. It is computed against the document
 * as it is right now, not stored, because the whole point is that it can become
 * true without anything about the annotation changing. A client that sees it set
 * must show the annotation with a warning rather than drawing it where it says --
 * the coordinates are still exactly where they were, but the page underneath
 * them is not.
 *
 * @property-read Annotation $resource
 */
final class AnnotationResource extends JsonResource
{
    public function __construct(
        Annotation $annotation,
        private readonly ?PindleDocument $document = null,
    ) {
        parent::__construct($annotation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $annotation = $this->resource;

        return [
            'id' => $annotation->id,
            'document_key' => $annotation->document_key,
            'page' => $annotation->page,
            'type' => $annotation->type->value,
            'rects' => $annotation->rects->toArray(),
            'color' => $annotation->color,
            'text_snippet' => $annotation->text_snippet,
            'orphaned' => $this->isOrphaned(),
            'author' => [
                'type' => $annotation->author_type,
                'id' => $annotation->author_id,
            ],
            'resolved_at' => $annotation->resolved_at?->toIso8601String(),
            'resolved_by_id' => $annotation->resolved_by_id,
            'meta' => $annotation->meta,
            'comments' => $annotation->relationLoaded('comments')
                ? CommentResource::collection($annotation->comments)->resolve($request)
                : [],
            'created_at' => $annotation->created_at->toIso8601String(),
            'updated_at' => $annotation->updated_at->toIso8601String(),
        ];
    }

    /**
     * False rather than null when the document cannot be resolved at all.
     *
     * There is nothing to be orphaned from, and a client cannot render a third
     * state usefully; the annotation simply has no document to contradict it.
     */
    private function isOrphaned(): bool
    {
        return $this->document instanceof PindleDocument
            && $this->resource->isOrphanedFrom($this->document);
    }
}
