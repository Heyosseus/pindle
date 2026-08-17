<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pindle\Documents\PageBounds;
use Pindle\Documents\PdfBounds;
use Pindle\Documents\PindleDocument;

function boundsOf(string $contents): PageBounds
{
    Storage::disk('documents')->put('probe.pdf', $contents);

    return PdfBounds::read(new PindleDocument('documents', 'probe.pdf'));
}

it('reads the page count the document asserts about itself', function (): void {
    $bounds = boundsOf(
        "%PDF-1.4\n2 0 obj\n<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
    );

    expect($bounds->isKnown())->toBeTrue()
        ->and($bounds->pages)->toBe(2)
        ->and($bounds->width)->toBe(595.0)
        ->and($bounds->height)->toBe(842.0);
});

it('counts the page nodes when the tree does not say', function (): void {
    $bounds = boundsOf(
        "%PDF-1.4\n"
        ."3 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
        ."4 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
        ."5 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
    );

    expect($bounds->pages)->toBe(3);
});

it('takes the largest page box, so a fold-out is not judged by page one', function (): void {
    $bounds = boundsOf(
        "%PDF-1.4\n2 0 obj\n<< /Type /Pages /Count 2 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
        ."4 0 obj\n<< /Type /Page /MediaBox [0 0 1191 1684] >>\nendobj\n"
    );

    expect($bounds->width)->toBe(1191.0)
        ->and($bounds->height)->toBe(1684.0);
});

it('falls back to the largest page a PDF may have when there is no box to read', function (): void {
    $bounds = boundsOf("%PDF-1.4\n2 0 obj\n<< /Type /Pages /Count 2 >>\nendobj\n");

    expect($bounds->pages)->toBe(2)
        ->and($bounds->width)->toBe(PageBounds::MAX_ORDINATE)
        ->and($bounds->height)->toBe(PageBounds::MAX_ORDINATE);
});

it('ignores a box with no area', function (): void {
    $bounds = boundsOf(
        "%PDF-1.4\n2 0 obj\n<< /Type /Pages /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /MediaBox [0 0 0 0] >>\nendobj\n"
    );

    expect($bounds->width)->toBe(PageBounds::MAX_ORDINATE);
});

it('gives up on a document with nothing it recognises', function (): void {
    expect(boundsOf('%PDF-1.7 and then nothing structural at all')->isKnown())->toBeFalse();
});

it('gives up on a count of zero', function (): void {
    expect(boundsOf("%PDF-1.4\n2 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\n")->isKnown())->toBeFalse();
});

it('gives up rather than undercount a file longer than it will read', function (): void {
    // Page nodes, no /Count, and more bytes than the window. Counting what fits
    // would give a floor, and a floor would reject annotations on pages that
    // genuinely exist.
    $bounds = boundsOf(
        "%PDF-1.4\n3 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] >>\nendobj\n"
        .str_repeat(' ', 2_200_000)
    );

    expect($bounds->isKnown())->toBeFalse();
});

it('gives up on a document that is not there', function (): void {
    expect(PdfBounds::read(new PindleDocument('documents', 'nowhere.pdf'))->isKnown())->toBeFalse();
});
