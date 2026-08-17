<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\PindleDocument;
use Pindle\Enums\AnnotationType;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Http\Requests\Concerns\ValidatesGeometry;

/**
 * A new mark on a page.
 *
 * The document is resolved here rather than taken from the request, and the hash
 * is taken from those bytes -- a client that could name its own `document_hash`
 * could mint an annotation that never orphans, which would defeat the one
 * mechanism protecting a reviewer from a silently re-issued contract.
 */
final class StoreAnnotationRequest extends FormRequest
{
    use ResolvesAnnotatable;
    use ValidatesGeometry;

    private ?Model $target = null;

    public function authorize(): bool
    {
        $this->authorizeDocument('create', $this->target());

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'annotatable_type' => ['required', 'string'],
            'annotatable_id' => ['required', 'string'],
            'document_key' => ['sometimes', 'string', 'max:255'],

            'page' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in($this->allowedTypes())],

            // The cap on how many anchors one annotation may carry lives in the
            // geometry check rather than here, so that there is one place it is
            // decided rather than two that have to agree.
            'rects' => ['present', 'array'],
            'rects.*.x1' => ['required', 'numeric'],
            'rects.*.y1' => ['required', 'numeric'],
            'rects.*.x2' => ['required', 'numeric'],
            'rects.*.y2' => ['required', 'numeric'],

            'color' => ['nullable', 'string', 'max:9', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'text_snippet' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkGeometry(
                    $validator,
                    $this->document(),
                    $this->integer('page'),
                    $this->anchors(),
                    AnnotationType::tryFrom($this->string('type')->value()),
                );
            },
        ];
    }

    public function target(): Model
    {
        if ($this->target instanceof Model) {
            return $this->target;
        }

        $type = $this->input('annotatable_type');
        $id = $this->input('annotatable_id');

        if (! is_string($type) || ! is_string($id) || $type === '' || $id === '') {
            abort(404);
        }

        return $this->target = $this->annotatable($type, $id);
    }

    public function documentKey(): string
    {
        $key = $this->input('document_key');

        return is_string($key) && $key !== '' ? $key : 'default';
    }

    public function document(): ?PindleDocument
    {
        return app(DocumentResolver::class)->resolve($this->target(), $this->documentKey());
    }
}
