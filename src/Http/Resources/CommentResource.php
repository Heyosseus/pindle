<?php

declare(strict_types=1);

namespace Pindle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pindle\Models\Comment;

/**
 * One comment. The body goes out exactly as it was typed -- no markup is
 * rendered, here or anywhere, so escaping it is the client's whole job.
 *
 * @property-read Comment $resource
 */
final class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'annotation_id' => $comment->annotation_id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'author' => [
                'type' => $comment->author_type,
                'id' => $comment->author_id,
            ],
            'created_at' => $comment->created_at->toIso8601String(),
            'updated_at' => $comment->updated_at->toIso8601String(),
        ];
    }
}
