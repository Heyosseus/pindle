<?php

declare(strict_types=1);

namespace Pindle\Documents;

/**
 * One byte range out of a `Range: bytes=…` header.
 *
 * Only the single-range form is honoured. Multi-range requests are answered with
 * the whole document instead, which is a response every client accepts and which
 * avoids assembling a multipart/byteranges body for something PDFium never asks
 * for.
 */
final readonly class Range
{
    public function __construct(
        public int $start,
        public int $end,
        public int $size,
    ) {}

    public static function parse(string $header, int $size): ?self
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1) {
            return null;
        }

        [, $from, $to] = $matches;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // "the last N bytes", which is how a reader finds a PDF's trailer.
            $length = (int) $to;

            return new self(max(0, $size - $length), $size - 1, $size);
        }

        $start = (int) $from;
        $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);

        return new self($start, $end, $size);
    }

    public function isSatisfiable(): bool
    {
        return $this->size > 0 && $this->start <= $this->end && $this->start < $this->size;
    }
}
