<?php

declare(strict_types=1);

namespace Pindle\Documents;

/**
 * What a document says about its own size, so far as it can be read cheaply.
 *
 * Geometry has to be checked on the server -- the client that drew it is not a
 * party to be trusted about where it drew -- and checking it needs to know how
 * many pages there are and how big they are. Getting that exactly right means
 * parsing PDF, which means a parser dependency, an object-stream decompressor
 * and a new class of bug for a package whose job is annotations.
 *
 * So the bounds are read where they can be read and left unknown where they
 * cannot, and validation treats the two differently: a known page count is
 * enforced exactly, an unknown one falls back to the configured ceiling. The
 * effect is that a plain PDF is checked precisely and an exotic one is still
 * checked against limits a hostile client cannot exceed -- rather than being
 * either rejected wrongly or waved through.
 */
final readonly class PageBounds
{
    /** A PDF page may not exceed 200 inches on a side, by the specification. */
    public const float MAX_ORDINATE = 14_400.0;

    /**
     * @param  int|null  $pages  Null when the count could not be read.
     */
    public function __construct(
        public ?int $pages,
        public float $width,
        public float $height,
    ) {}

    /**
     * What is assumed about a document nothing could be read from.
     */
    public static function unknown(): self
    {
        return new self(null, self::MAX_ORDINATE, self::MAX_ORDINATE);
    }

    public function isKnown(): bool
    {
        return $this->pages !== null;
    }

    /**
     * Whether a page number could name a page of this document.
     *
     * @param  int  $ceiling  The configured cap, used when the count is unknown.
     */
    public function hasPage(int $page, int $ceiling): bool
    {
        if ($page < 1) {
            return false;
        }

        return $page <= ($this->pages ?? $ceiling);
    }
}
