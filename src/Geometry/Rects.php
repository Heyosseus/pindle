<?php

declare(strict_types=1);

namespace Pindle\Geometry;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Pindle\Exceptions\InvalidGeometry;
use Traversable;

/**
 * The rectangles one annotation is anchored to.
 *
 * A highlight running across three lines of text is three rectangles, not one
 * box drawn around all three -- a single bounding box would paint over the
 * margin and over whatever sits between the lines. An area or a note is exactly
 * one. The count is therefore meaningful, and validation caps it.
 *
 * @implements IteratorAggregate<int, Rect>
 */
final readonly class Rects implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<Rect>  $rects
     */
    private function __construct(public array $rects) {}

    /**
     * @param  list<Rect>  $rects
     */
    public static function make(array $rects): self
    {
        return new self(array_values(array_map(
            static fn (Rect $rect): Rect => $rect->normalized(),
            $rects,
        )));
    }

    /**
     * The rectangles as they arrive from the API or from the database column.
     *
     * @param  array<array-key, mixed>  $rects
     */
    public static function fromArray(array $rects): self
    {
        $parsed = [];

        foreach ($rects as $rect) {
            if (! is_array($rect)) {
                throw InvalidGeometry::notARectangle();
            }

            $parsed[] = Rect::fromArray($rect);
        }

        return self::make($parsed);
    }

    public function isEmpty(): bool
    {
        return $this->rects === [];
    }

    /**
     * Whether every rectangle sits inside a page of the given size.
     */
    public function fitWithin(float $width, float $height): bool
    {
        foreach ($this->rects as $rect) {
            if (! $rect->fitsWithin($width, $height)) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return count($this->rects);
    }

    /**
     * @return Traversable<int, Rect>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rects);
    }

    /**
     * @return list<array{x1: float, y1: float, x2: float, y2: float}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Rect $rect): array => $rect->toArray(), $this->rects);
    }

    /**
     * @return list<array{x1: float, y1: float, x2: float, y2: float}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
