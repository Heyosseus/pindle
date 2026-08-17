<?php

declare(strict_types=1);

use Pindle\Documents\PageBounds;
use Pindle\Documents\Range;
use Pindle\Support\Author;

it('reads a closed range', function (): void {
    $range = Range::parse('bytes=10-19', 100);

    expect($range?->start)->toBe(10)
        ->and($range?->end)->toBe(19)
        ->and($range?->isSatisfiable())->toBeTrue();
});

it('reads an open-ended range as the rest of the file', function (): void {
    expect(Range::parse('bytes=90-', 100)?->end)->toBe(99);
});

it('clamps an end beyond the last byte', function (): void {
    expect(Range::parse('bytes=90-999', 100)?->end)->toBe(99);
});

it('reads a suffix range as the last bytes', function (): void {
    $range = Range::parse('bytes=-20', 100);

    expect($range?->start)->toBe(80)
        ->and($range?->end)->toBe(99);
});

it('gives the whole file when a suffix is longer than the file', function (): void {
    expect(Range::parse('bytes=-500', 100)?->start)->toBe(0);
});

it('reads nothing out of a range with neither end', function (): void {
    expect(Range::parse('bytes=-', 100))->toBeNull();
});

it('reads nothing out of a header it does not understand', function (): void {
    expect(Range::parse('bytes=0-9,20-29', 100))->toBeNull()
        ->and(Range::parse('items=0-9', 100))->toBeNull();
});

it('knows a range it cannot satisfy', function (): void {
    expect(Range::parse('bytes=500-600', 100)?->isSatisfiable())->toBeFalse()
        ->and(Range::parse('bytes=0-9', 0)?->isSatisfiable())->toBeFalse();
});

it('has no page before the first, whatever the ceiling', function (): void {
    expect((new PageBounds(10, 595.0, 842.0))->hasPage(0, 5_000))->toBeFalse()
        ->and(PageBounds::unknown()->hasPage(0, 5_000))->toBeFalse();
});

it('counts an unauthenticated actor as a guest', function (): void {
    $author = Author::of(null);

    expect($author->type)->toBe('guest')
        ->and($author->id)->toBe('');
});
