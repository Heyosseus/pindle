<?php

declare(strict_types=1);

namespace Pindle\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Pindle\Documents\PageBounds;
use Pindle\Documents\PdfBounds;
use Pindle\Documents\PindleDocument;
use Pindle\Enums\AnnotationType;
use Pindle\Exceptions\InvalidGeometry;
use Pindle\Geometry\Rects;

/**
 * The checks that cannot be expressed as validation rules, because they need the
 * document.
 *
 * Everything here exists because the client is not a source of truth about where
 * it drew. Rules alone can say "four numbers"; only the document can say whether
 * those four numbers name a place on a page that exists.
 *
 * @mixin \Illuminate\Foundation\Http\FormRequest
 */
trait ValidatesGeometry
{
    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float}>  $rects
     */
    protected function checkGeometry(
        Validator $validator,
        ?PindleDocument $document,
        int $page,
        array $rects,
        ?AnnotationType $type,
    ): void {
        $bounds = $document instanceof PindleDocument ? PdfBounds::read($document) : PageBounds::unknown();

        if (! $bounds->hasPage($page, $this->pageCeiling())) {
            $validator->errors()->add('page', $bounds->isKnown()
                ? 'The document has no page '.$page.'.'
                : 'The page is outside the range Pindle will accept.');
        }

        $anchors = Rects::fromArray($rects);

        if ($anchors->count() > $this->rectCeiling()) {
            $validator->errors()->add('rects', 'An annotation may not carry more than '.$this->rectCeiling().' anchors.');
        }

        // A note or an area is one place on a page. More than one rectangle
        // describes something the viewer has no way to draw back.
        if ($type?->isSingleRect() === true && $anchors->count() > 1) {
            $validator->errors()->add('rects', 'A '.$type->value.' is anchored to exactly one rectangle.');
        }

        if (! $anchors->fitWithin($bounds->width, $bounds->height)) {
            $validator->errors()->add('rects', 'An anchor falls outside the bounds of the page.');
        }
    }

    protected function pageCeiling(): int
    {
        $ceiling = config('pindle.annotations.max_pages');

        return is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : 5_000;
    }

    protected function rectCeiling(): int
    {
        $ceiling = config('pindle.annotations.max_rects');

        return is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : 64;
    }

    /**
     * The annotation types this installation allows.
     *
     * @return list<string>
     */
    protected function allowedTypes(): array
    {
        $configured = config('pindle.annotations.types');

        if (! is_array($configured)) {
            return array_column(AnnotationType::cases(), 'value');
        }

        $types = [];

        foreach ($configured as $type) {
            if (is_string($type) && AnnotationType::tryFrom($type) instanceof AnnotationType) {
                $types[] = $type;
            }
        }

        return $types === [] ? array_column(AnnotationType::cases(), 'value') : $types;
    }

    /**
     * The anchors as the geometry checks want them.
     *
     * @return list<array{x1: float, y1: float, x2: float, y2: float}>
     */
    public function anchors(): array
    {
        $rects = $this->input('rects');

        if (! is_array($rects)) {
            return [];
        }

        try {
            return Rects::fromArray($rects)->toArray();
        } catch (InvalidGeometry) {
            // Reached only when this is called before the shape rules have run,
            // or from outside a validated request. The rules have the last word
            // on anchors that are not anchors; there is nothing to add here.
            return [];
        }
    }
}
