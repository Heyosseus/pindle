<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Models\Comment;
use Pindle\Pindle;

/**
 * Correcting something already said.
 */
final class UpdateCommentRequest extends FormRequest
{
    use ResolvesAnnotatable;

    private ?Comment $comment = null;

    public function authorize(): bool
    {
        $annotatable = $this->comment()->annotation()->first()?->annotatable()->first();

        if ($annotatable === null) {
            abort(404);
        }

        $this->authorizeDocument('update', $annotatable);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.$this->maxLength()],
        ];
    }

    public function comment(): Comment
    {
        if ($this->comment instanceof Comment) {
            return $this->comment;
        }

        $id = $this->route('comment');

        $comment = is_string($id) ? Pindle::commentModel()::query()->find($id) : null;

        if (! $comment instanceof Comment) {
            abort(404);
        }

        return $this->comment = $comment;
    }

    private function maxLength(): int
    {
        $max = config('pindle.comments.max_length');

        return is_numeric($max) && (int) $max > 0 ? (int) $max : 2_000;
    }
}
