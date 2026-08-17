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
 * Moving an orphaned mark onto the document that replaced the one it was drawn on.
 *
 * The geometry is checked against the *new* document, not the old one -- the
 * whole point is that the page has changed, and an anchor that fitted the
 * previous revision may fall off this one.
 *
 * Deliberately a separate endpoint from an ordinary update, and deliberately
 * not automatic. Rewriting `document_hash` is the one thing that erases the
 * evidence a reviewer is relying on, so it takes an explicit request, an
 * explicit authorisation, and it fires an event of its own.
 */
final class ReanchorAnnotationRequest extends FormRequest
{
    use ResolvesAnnotatable;
    use ValidatesGeometry;

    private ?Annotation $annotation = null;

    private ?Model $target = null;

    public function authorize(): bool
    {
        $this->authorizeDocument('update', $this->target());

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1'],
            'rects' => ['required', 'array', 'min:1'],
            'rects.*.x1' => ['required', 'numeric'],
            'rects.*.y1' => ['required', 'numeric'],
            'rects.*.x2' => ['required', 'numeric'],
            'rects.*.y2' => ['required', 'numeric'],
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

                if (! $this->document() instanceof PindleDocument) {
                    $validator->errors()->add('page', 'There is no document to move this onto.');

                    return;
                }

                $this->checkGeometry(
                    $validator,
                    $this->document(),
                    $this->integer('page'),
                    $this->anchors(),
                    $this->annotation()->type,
                );
            },
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

    public function document(): ?PindleDocument
    {
        return app(DocumentResolver::class)->resolve($this->target(), $this->annotation()->document_key);
    }
}
