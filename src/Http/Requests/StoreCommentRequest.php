<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Pindle;

/**
 * Something to say about a mark, or an answer to something already said.
 */
final class StoreCommentRequest extends FormRequest
{
    use ResolvesAnnotatable;

    private ?Annotation $annotation = null;

    public function authorize(): bool
    {
        $annotatable = $this->annotation()->annotatable()->first();

        if ($annotatable === null) {
            abort(404);
        }

        $this->authorizeDocument('create', $annotatable);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.$this->maxLength()],
            'parent_id' => ['nullable', 'string'],
        ];
    }

    public function annotation(): Annotation
    {
        if ($this->annotation instanceof Annotation) {
            return $this->annotation;
        }

        $id = $this->route('annotation');

        $annotation = is_string($id) ? Pindle::query()->find($id) : null;

        if (! $annotation instanceof Annotation) {
            abort(404);
        }

        return $this->annotation = $annotation;
    }

    /**
     * The comment this one answers, flattened to the top of its thread.
     *
     * A reply to a reply attaches to the same parent rather than nesting a third
     * time. A parent from another annotation is ignored rather than honoured --
     * it would put a thread on two documents at once.
     */
    public function parent(): ?Comment
    {
        $id = $this->input('parent_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $parent = Pindle::commentModel()::query()
            ->where('annotation_id', $this->annotation()->id)
            ->find($id);

        return $parent instanceof Comment ? $parent->threadRoot() : null;
    }

    private function maxLength(): int
    {
        $max = config('pindle.comments.max_length');

        return is_numeric($max) && (int) $max > 0 ? (int) $max : 2_000;
    }
}
