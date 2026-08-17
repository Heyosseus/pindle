<?php

declare(strict_types=1);

namespace Pindle\Documents;

/**
 * Reads a document's page count and page size without parsing PDF properly.
 *
 * Deliberately shallow. It scans the head of the file for the two things
 * validation needs and gives up cleanly on anything it does not recognise --
 * cross-reference streams, object streams, encrypted documents. Giving up is a
 * supported outcome, not a failure: {@see PageBounds::unknown()} still bounds a
 * hostile client, it just bounds it by configuration rather than by the file.
 *
 * The alternative was a real PDF parser as a hard dependency of a package whose
 * entire premise is that it installs in five minutes.
 */
final class PdfBounds
{
    /** How much of the file to look at. Both markers sit in the first pages of
     * every document that carries them uncompressed at all. */
    private const int WINDOW = 2_097_152;

    public static function read(PindleDocument $document): PageBounds
    {
        $head = self::head($document);

        // Covers both "no such file" and "an adapter that would not open it":
        // either way there is nothing to read the bounds from, and the answer is
        // the same.
        if ($head === null) {
            return PageBounds::unknown();
        }

        $pages = self::pages($head);

        if ($pages === null) {
            return PageBounds::unknown();
        }

        [$width, $height] = self::largestMediaBox($head);

        return new PageBounds($pages, $width, $height);
    }

    private static function head(PindleDocument $document): ?string
    {
        if (! $document->exists()) {
            return null;
        }

        $stream = $document->filesystem()->readStream($document->path);

        if (! is_resource($stream)) {
            return null; // @codeCoverageIgnore
        }

        $head = stream_get_contents($stream, self::WINDOW);

        fclose($stream);

        return $head === false ? null : $head;
    }

    /**
     * The page count, preferring the page tree's own /Count over counting nodes.
     *
     * /Count is one number and is what the document itself asserts. Counting
     * `/Type /Page` nodes is the fallback, and it is only trustworthy when the
     * whole file fitted inside the window -- a truncated read would undercount
     * and reject annotations on pages that genuinely exist.
     */
    private static function pages(string $head): ?int
    {
        if (preg_match('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $head, $matches) === 1) {
            $count = (int) $matches[1];

            return $count > 0 ? $count : null;
        }

        if (strlen($head) >= self::WINDOW) {
            // The file was longer than the window, so any node count is a floor
            // rather than a total, and a floor is worse than no answer at all.
            return null;
        }

        $count = preg_match_all('/\/Type\s*\/Page[^s]/', $head);

        return $count > 0 ? $count : null;
    }

    /**
     * The largest page box in the document.
     *
     * The largest rather than the first, because a document may mix sizes and
     * validating everything against page one's box would reject a legitimate
     * annotation on a fold-out drawing.
     *
     * @return array{float, float}
     */
    private static function largestMediaBox(string $head): array
    {
        $pattern = '/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/';

        if (preg_match_all($pattern, $head, $matches, PREG_SET_ORDER) === 0) {
            return [PageBounds::MAX_ORDINATE, PageBounds::MAX_ORDINATE];
        }

        $width = 0.0;
        $height = 0.0;

        foreach ($matches as $box) {
            $width = max($width, abs((float) $box[3] - (float) $box[1]));
            $height = max($height, abs((float) $box[4] - (float) $box[2]));
        }

        return $width > 0.0 && $height > 0.0
            ? [$width, $height]
            : [PageBounds::MAX_ORDINATE, PageBounds::MAX_ORDINATE];
    }
}
