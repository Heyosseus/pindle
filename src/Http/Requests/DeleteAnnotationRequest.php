<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Models\Annotation;
use Pindle\Pindle;

/**
 * Taking a mark down.
 *
 * Its own request rather than a mode of the update one, because it asks the
 * policy a different question -- an application may well let a reviewer redraw
 * their own highlight but not remove somebody else's.
 */
final class DeleteAnnotationRequest extends FormRequest
{
    use ResolvesAnnotatable;

    private ?Annotation $annotation = null;

    public function authorize(): bool
    {
        $annotatable = $this->annotation()->annotatable()->first();

        if ($annotatable === null) {
            abort(404);
        }

        $this->authorizeDocument('delete', $annotatable);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
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
}
