<?php

declare(strict_types=1);

namespace Pindle\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\PindleDocument;
use Pindle\Events\AnnotationReanchored;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Http\Concerns\RespondsWithJson;
use Pindle\Http\Requests\DeleteAnnotationRequest;
use Pindle\Http\Requests\ListAnnotationsRequest;
use Pindle\Http\Requests\ReanchorAnnotationRequest;
use Pindle\Http\Requests\StoreAnnotationRequest;
use Pindle\Http\Requests\UpdateAnnotationRequest;
use Pindle\Http\Resources\AnnotationResource;
use Pindle\Models\Annotation;
use Pindle\Pindle;
use Pindle\Support\Author;
use Pindle\Support\Key;
use Symfony\Component\HttpFoundation\Response;

/**
 * The annotation half of the API.
 *
 * Nothing here decides who may do what -- the form requests have already asked
 * the policy about the owning model by the time a method runs. That separation
 * is what makes authorisation auditable: there is exactly one place per endpoint
 * where access is decided, and it is the same place validation happens.
 */
final class AnnotationController
{
    use ResolvesAnnotatable;
    use RespondsWithJson;

    public function index(ListAnnotationsRequest $request): JsonResponse
    {
        $annotatable = $request->target();
        $key = $request->documentKey();

        $annotations = Pindle::query()
            ->forDocument($annotatable, $key)
            ->with('comments')
            ->oldest()
            ->get();

        // Resolved once for the whole list rather than once per annotation: the
        // hash is a read of the file, and a hundred highlights on one contract
        // would otherwise be a hundred reads of it.
        $document = app(DocumentResolver::class)->resolve($annotatable, $key);

        return $this->json([
            'data' => $annotations
                ->map(fn (Annotation $annotation): array => (new AnnotationResource($annotation, $document))->resolve($request))
                ->all(),
            'document' => $this->documentPayload($document),
        ]);
    }

    public function store(StoreAnnotationRequest $request): JsonResponse
    {
        $annotatable = $request->target();
        $document = $request->document();
        $author = Author::current();

        $annotation = Pindle::annotationModel()::query()->create([
            'annotatable_type' => $annotatable->getMorphClass(),
            'annotatable_id' => Key::of($annotatable),
            'document_key' => $request->documentKey(),

            // Taken from the bytes, never from the request. A client that could
            // name its own hash could mint an annotation that never orphans,
            // which would defeat the one mechanism protecting a reviewer from a
            // silently re-issued document.
            'document_hash' => $document?->hash() ?? str_repeat('0', 64),

            'page' => $request->integer('page'),
            'type' => $request->string('type')->value(),
            'rects' => $request->anchors(),
            'color' => $request->input('color'),
            'text_snippet' => $request->input('text_snippet'),
            'author_type' => $author->type,
            'author_id' => $author->id,
            'meta' => $request->input('meta'),
        ]);

        return $this->json(
            (new AnnotationResource($annotation, $document))->resolve($request),
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateAnnotationRequest $request): JsonResponse
    {
        $annotation = $request->annotation();

        $changes = [];

        foreach (['page', 'color', 'text_snippet', 'meta'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->input($field);
            }
        }

        if ($request->has('rects')) {
            $changes['rects'] = $request->anchors();
        }

        if ($request->has('resolved')) {
            $resolved = $request->boolean('resolved');

            $changes['resolved_at'] = $resolved ? now() : null;
            $changes['resolved_by_id'] = $resolved ? Author::current()->id : null;
        }

        $annotation->update($changes);

        // Loaded rather than left off: a client that has just moved a mark
        // redraws its thread from this response, and an absent relation would
        // read as an emptied thread.
        $annotation->refresh()->load('comments');

        return $this->json(
            (new AnnotationResource($annotation, $request->document()))->resolve($request),
        );
    }

    /**
     * Move an orphaned mark onto the document that replaced the one it was
     * drawn on, and re-hash it against those bytes.
     *
     * The hash comes from the document, never from the request -- the same rule
     * as creation, and for the same reason: a client that could choose its own
     * hash could declare any annotation current.
     */
    public function reanchor(ReanchorAnnotationRequest $request): JsonResponse
    {
        $annotation = $request->annotation();
        $document = $request->document();

        $previous = $annotation->document_hash;

        $annotation->forceFill([
            'page' => $request->integer('page'),
            'rects' => $request->anchors(),
            'document_hash' => $document?->hash() ?? $previous,
        ])->save();

        AnnotationReanchored::dispatch($annotation, $previous, Auth::user());

        $annotation->refresh()->load('comments');

        return $this->json((new AnnotationResource($annotation, $document))->resolve($request));
    }

    public function destroy(DeleteAnnotationRequest $request): Response
    {
        $request->annotation()->delete();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * What a client needs in order to fetch the bytes, and to tell whether what
     * it already holds is still the same document.
     *
     * @return array<string, mixed>|null
     */
    private function documentPayload(?PindleDocument $document): ?array
    {
        if (! $document instanceof PindleDocument || ! $document->exists()) {
            return null;
        }

        return [
            'key' => $document->key,
            'filename' => $document->filename(),
            'hash' => $document->hash(),
            'size' => $document->size(),
        ];
    }
}
