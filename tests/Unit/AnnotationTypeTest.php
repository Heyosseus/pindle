<?php

declare(strict_types=1);

use Pindle\Enums\AnnotationType;

it('knows which types are anchored to a single rectangle', function (): void {
    expect(AnnotationType::Note->isSingleRect())->toBeTrue()
        ->and(AnnotationType::Area->isSingleRect())->toBeTrue()
        ->and(AnnotationType::Highlight->isSingleRect())->toBeFalse()
        ->and(AnnotationType::Ink->isSingleRect())->toBeFalse();
});

it('speaks the four names the API and the viewer share', function (): void {
    expect(array_column(AnnotationType::cases(), 'value'))
        ->toBe(['highlight', 'note', 'area', 'ink']);
});
