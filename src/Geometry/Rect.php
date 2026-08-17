<?php

declare(strict_types=1);

namespace Pindle\Geometry;

use JsonSerializable;
use Pindle\Exceptions\InvalidGeometry;

/**
 * One axis-aligned rectangle in PDF user space.
 *
 * The origin is the *bottom-left* of the page and the unit is the point, which
 * is PDF's own coordinate system and not the browser's. This is the single most
 * important decision in the package and the easiest one to get wrong, so it is
 * worth saying why plainly:
 *
 * A rectangle in DOM coordinates is only meaningful alongside the zoom level,
 * the device pixel ratio, the container width and the rotation that produced it.
 * Persist one and you have persisted a screenshot of a viewport, and the
 * highlight lands somewhere else the moment any of those four change -- on a
 * phone, on a retina display, at 150%, or after the page is turned sideways.
 *
 * User-space points have none of those dependencies. They are the coordinates
 * the document itself is drawn in. Converting to the viewport happens at draw
 * time, every time, and is thrown away afterwards.
 */
final readonly class Rect implements JsonSerializable
{
    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
    ) {
        foreach ([$x1, $y1, $x2, $y2] as $ordinate) {
            if (! is_finite($ordinate)) {
                throw InvalidGeometry::nonFiniteOrdinate();
            }
        }
    }

    /**
     * A rectangle from the four keys the API speaks in.
     *
     * @param  array<array-key, mixed>  $rect
     */
    public static function fromArray(array $rect): self
    {
        $ordinates = [];

        foreach (['x1', 'y1', 'x2', 'y2'] as $key) {
            $value = $rect[$key] ?? null;

            if (! is_int($value) && ! is_float($value) && (! is_string($value) || ! is_numeric($value))) {
                throw InvalidGeometry::missingOrdinate($key);
            }

            $ordinates[$key] = (float) $value;
        }

        return new self($ordinates['x1'], $ordinates['y1'], $ordinates['x2'], $ordinates['y2']);
    }

    /**
     * The same rectangle with its corners the right way round.
     *
     * A selection dragged upwards or leftwards arrives with x2 < x1 or y2 < y1,
     * which is a perfectly good description of the same area and a poor one to
     * store: every later comparison would have to handle both orders. Sorting
     * happens once, here, on the way in.
     */
    public function normalized(): self
    {
        return new self(
            min($this->x1, $this->x2),
            min($this->y1, $this->y2),
            max($this->x1, $this->x2),
            max($this->y1, $this->y2),
        );
    }

    public function width(): float
    {
        return abs($this->x2 - $this->x1);
    }

    public function height(): float
    {
        return abs($this->y2 - $this->y1);
    }

    /**
     * Whether this rectangle sits inside a page of the given size.
     *
     * Both ends are checked against zero as well as against the page, because a
     * negative ordinate is off the page in the other direction and describes an
     * anchor that can never be drawn.
     */
    public function fitsWithin(float $width, float $height): bool
    {
        $rect = $this->normalized();

        return $rect->x1 >= 0.0
            && $rect->y1 >= 0.0
            && $rect->x2 <= $width
            && $rect->y2 <= $height;
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float}
     */
    public function toArray(): array
    {
        return ['x1' => $this->x1, 'y1' => $this->y1, 'x2' => $this->x2, 'y2' => $this->y2];
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
