<?php

declare(strict_types=1);

namespace Pindle\Enums;

/**
 * What an annotation is, which decides how many rectangles it may carry.
 *
 * These are the four EmbedPDF draws and the four Pindle stores. Anything else an
 * application wants to record about a spot on a page belongs in `meta`, not in a
 * fifth type -- a type is a rendering contract, and the viewer can only render
 * these.
 */
enum AnnotationType: string
{
    /** Text selection, one rectangle per line it runs across. */
    case Highlight = 'highlight';

    /** A pinned marker with a thread hanging off it; a single small rectangle. */
    case Note = 'note';

    /** A box drawn over part of the page, exactly one rectangle. */
    case Area = 'area';

    /** Freehand, stored as the rectangles of its strokes. */
    case Ink = 'ink';

    /**
     * Whether this type is anchored to exactly one rectangle.
     *
     * A highlight and an ink stroke span as many as the shape needs; a note and
     * an area are one place each, and more than one would mean the client sent
     * something the viewer cannot draw back.
     */
    public function isSingleRect(): bool
    {
        return $this === self::Note || $this === self::Area;
    }
}
