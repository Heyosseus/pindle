<?php

declare(strict_types=1);

namespace Pindle\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Pindle\Http\Concerns\RespondsWithJson;
use Pindle\Http\Requests\DeleteCommentRequest;
use Pindle\Http\Requests\StoreCommentRequest;
use Pindle\Http\Requests\UpdateCommentRequest;
use Pindle\Http\Resources\CommentResource;
use Pindle\Pindle;
use Pindle\Support\Author;
use Symfony\Component\HttpFoundation\Response;

/**
 * The comment half of the API.
 */
final class CommentController
{
    use RespondsWithJson;

    public function store(StoreCommentRequest $request): JsonResponse
    {
        $author = Author::current();

        $comment = Pindle::commentModel()::query()->create([
            'annotation_id' => $request->annotation()->id,

            // Flattened to the top of its thread by the request, so a reply to a
            // reply lands beside it rather than under it.
            'parent_id' => $request->parent()?->id,

            'author_type' => $author->type,
            'author_id' => $author->id,
            'body' => $request->string('body')->value(),
        ]);

        return $this->json(
            (new CommentResource($comment))->resolve($request),
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateCommentRequest $request): JsonResponse
    {
        $comment = $request->comment();

        $comment->update(['body' => $request->string('body')->value()]);

        return $this->json((new CommentResource($comment->refresh()))->resolve($request));
    }

    public function destroy(DeleteCommentRequest $request): Response
    {
        $request->comment()->delete();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
