<?php

declare(strict_types=1);

use Pindle\Exceptions\InvalidGeometry;
use Pindle\Geometry\Rect;
use Pindle\Geometry\Rects;

it('keeps the ordinates it was given', function (): void {
    $rect = new Rect(72.0, 640.2, 310.5, 655.8);

    expect($rect->x1)->toBe(72.0)
        ->and($rect->y1)->toBe(640.2)
        ->and($rect->x2)->toBe(310.5)
        ->and($rect->y2)->toBe(655.8);
});

it('sorts the corners of a selection dragged backwards', function (): void {
    $rect = (new Rect(310.5, 655.8, 72.0, 640.2))->normalized();

    expect($rect->toArray())->toBe(['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]);
});

it('measures itself regardless of which way it was drawn', function (): void {
    expect((new Rect(310.5, 655.8, 72.0, 640.2))->width())->toEqualWithDelta(238.5, 0.0001)
        ->and((new Rect(72.0, 640.2, 310.5, 655.8))->height())->toEqualWithDelta(15.6, 0.0001);
});

it('reads the four ordinates out of an array, numeric strings included', function (): void {
    expect(Rect::fromArray(['x1' => 1, 'y1' => '2.5', 'x2' => 3.0, 'y2' => 4])->toArray())
        ->toBe(['x1' => 1.0, 'y1' => 2.5, 'x2' => 3.0, 'y2' => 4.0]);
});

it('refuses an anchor missing an ordinate', function (): void {
    Rect::fromArray(['x1' => 1, 'y1' => 2, 'x2' => 3]);
})->throws(InvalidGeometry::class, 'numeric "y2" ordinate');

it('refuses an ordinate that is not a number', function (): void {
    Rect::fromArray(['x1' => 'left', 'y1' => 2, 'x2' => 3, 'y2' => 4]);
})->throws(InvalidGeometry::class, 'numeric "x1" ordinate');

it('refuses an ordinate that is not finite', function (): void {
    new Rect(INF, 0.0, 1.0, 1.0);
})->throws(InvalidGeometry::class, 'finite number');

it('refuses an anchor that is not a rectangle at all', function (): void {
    Rects::fromArray(['not-a-rect']);
})->throws(InvalidGeometry::class, 'x1, y1, x2 and y2');

it('knows whether an anchor fits on the page', function (): void {
    $rect = new Rect(72.0, 640.2, 310.5, 655.8);

    expect($rect->fitsWithin(612.0, 792.0))->toBeTrue()
        ->and($rect->fitsWithin(200.0, 792.0))->toBeFalse()
        ->and((new Rect(-1.0, 10.0, 20.0, 20.0))->fitsWithin(612.0, 792.0))->toBeFalse();
});

it('normalises every rectangle in a set as it is built', function (): void {
    $rects = Rects::fromArray([
        ['x1' => 310.5, 'y1' => 655.8, 'x2' => 72.0, 'y2' => 640.2],
        ['x1' => 72.0, 'y1' => 620.0, 'x2' => 200.0, 'y2' => 635.0],
    ]);

    expect($rects)->toHaveCount(2)
        ->and($rects->isEmpty())->toBeFalse()
        ->and($rects->toArray()[0])->toBe(['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]);
});

it('holds a highlight as one rectangle per line it runs across', function (): void {
    $lines = Rects::make([
        new Rect(72.0, 640.0, 520.0, 655.0),
        new Rect(72.0, 622.0, 520.0, 637.0),
        new Rect(72.0, 604.0, 310.0, 619.0),
    ]);

    expect($lines)->toHaveCount(3)
        ->and(iterator_to_array($lines))->each->toBeInstanceOf(Rect::class);
});

it('answers whether every rectangle fits on the page', function (): void {
    $rects = Rects::make([new Rect(72.0, 640.0, 520.0, 655.0), new Rect(72.0, 622.0, 900.0, 637.0)]);

    expect($rects->fitWithin(612.0, 792.0))->toBeFalse()
        ->and(Rects::make([new Rect(72.0, 640.0, 520.0, 655.0)])->fitWithin(612.0, 792.0))->toBeTrue();
});

it('is empty when it holds nothing', function (): void {
    expect(Rects::make([])->isEmpty())->toBeTrue()
        ->and(Rects::make([])->fitWithin(612.0, 792.0))->toBeTrue();
});

it('serialises to the shape the API speaks in', function (): void {
    $rects = Rects::make([new Rect(72.0, 640.2, 310.5, 655.8)]);

    expect(json_encode($rects, JSON_PRESERVE_ZERO_FRACTION))
        ->toBe('[{"x1":72.0,"y1":640.2,"x2":310.5,"y2":655.8}]')
        ->and(json_encode($rects->rects[0], JSON_PRESERVE_ZERO_FRACTION))
        ->toBe('{"x1":72.0,"y1":640.2,"x2":310.5,"y2":655.8}');
});
