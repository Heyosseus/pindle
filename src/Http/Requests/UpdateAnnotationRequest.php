<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\PindleDocument;
use Pindle\Http\Concerns\ResolvesAnnotatable;
use Pindle\Http\Requests\Concerns\ValidatesGeometry;
use Pindle\Models\Annotation;
use Pindle\Pindle;

/**
 * Moving a mark, recolouring it, or settling it.
 *
 * Resolving and settling ask a different question of the policy than moving
 * does, because an application may well let a reviewer close a point without
 * letting them redraw it.
 */
final class UpdateAnnotationRequest extends FormRequest
{
    use ResolvesAnnotatable;
    use ValidatesGeometry;

    private ?Annotation $annotation = null;

    private ?Model $target = null;

    public function authorize(): bool
    {
        $this->authorizeDocument($this->onlyResolving() ? 'resolve' : 'update', $this->target());

        return true;
    }

    /**
     * The model the annotation is written on.
     *
     * An annotation whose owner has been hard-deleted out from under it is a 404
     * rather than a 500: there is no model left to ask the policy about, and
     * without an answer from the policy nothing here may proceed.
     */
    public function target(): Model
    {
        if ($this->target instanceof Model) {
            return $this->target;
        }

        $annotatable = $this->annotation()->annotatable()->first();

        if (! $annotatable instanceof Model) {
            abort(404);
        }

        return $this->target = $annotatable;
    }

    /**
     * The document the annotation sits on, as it is now.
     */
    public function document(): ?PindleDocument
    {
        return app(DocumentResolver::class)->resolve($this->target(), $this->annotation()->document_key);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],

            'rects' => ['sometimes', 'array'],
            'rects.*.x1' => ['required', 'numeric'],
            'rects.*.y1' => ['required', 'numeric'],
            'rects.*.x2' => ['required', 'numeric'],
            'rects.*.y2' => ['required', 'numeric'],

            'color' => ['sometimes', 'nullable', 'string', 'max:9', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'text_snippet' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'resolved' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->has('rects')) {
                    return;
                }

                $annotation = $this->annotation();

                $this->checkGeometry(
                    $validator,
                    $this->document(),
                    $this->has('page') ? $this->integer('page') : $annotation->page,
                    $this->anchors(),
                    $annotation->type,
                );
            },
        ];
    }

    /**
     * The annotation being changed, read through Pindle's own query so that an
     * application's `scopeUsing` constraint applies here as it does everywhere.
     */
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
     * Whether this request only opens or closes a point, changing nothing about
     * where it sits.
     */
    public function onlyResolving(): bool
    {
        return $this->has('resolved') && array_diff(array_keys($this->all()), ['resolved']) === [];
    }
}
