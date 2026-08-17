<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Pindle\Enums\AnnotationType;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Tests\DisabledTestCase;
use Pindle\Tests\FilamentTestCase;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\LivewireTestCase;
use Pindle\Tests\MisconfiguredTestCase;
use Pindle\Tests\ScheduledTestCase;
use Pindle\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch and the pruning schedule are both read while the provider
 * boots, so the tests that prove them boot differently configured applications.
 */
uses(DisabledTestCase::class)->in('Disabled');
uses(ScheduledTestCase::class)->in('Scheduled');
uses(MisconfiguredTestCase::class)->in('Misconfigured');

/*
 * The adapters are optional, so the cases that boot Livewire and Filament are
 * scoped to their own directories. Everything else in the suite runs with
 * neither installed, which is how "never a hard dependency" is actually proven.
 */
uses(LivewireTestCase::class)->in('Livewire');
uses(FilamentTestCase::class)->in('Filament');

/**
 * A PDF on the fake disk, and an invoice pointing at it.
 *
 * The bytes are not a real PDF and do not need to be: nothing on the server side
 * parses them. What matters is that they hash to something stable, and that
 * changing them changes the hash -- which is the whole of orphan detection.
 */
function invoiceWithDocument(string $contents = '%PDF-1.7 first issue', string $path = 'invoices/1.pdf'): Invoice
{
    Storage::disk('documents')->put($path, $contents);

    return Invoice::query()->create(['pdf_path' => $path]);
}

/**
 * An annotation on a model's document, hashed against whatever that document
 * currently is.
 *
 * @param  list<array{x1: float, y1: float, x2: float, y2: float}>  $rects
 */
function annotate(
    Model $model,
    array $rects,
    string $key = 'default',
    int $page = 1,
    ?Model $author = null,
    AnnotationType $type = AnnotationType::Highlight,
): Annotation {
    /** @var Annotation $annotation */
    $annotation = Annotation::query()->create([
        'annotatable_type' => $model->getMorphClass(),
        'annotatable_id' => (string) $model->getKey(),
        'document_key' => $key,
        'document_hash' => documentHash($model, $key),
        'page' => $page,
        'type' => $type,
        'rects' => $rects,
        'author_type' => $author?->getMorphClass() ?? 'user',
        'author_id' => (string) ($author?->getKey() ?? '1'),
    ]);

    return $annotation;
}

/**
 * A two-page A4 document whose structure the shallow bounds reader can see.
 *
 * Not a PDF any reader would open -- it has no content streams and no xref --
 * but it carries the page tree and the media boxes, which is all the geometry
 * checks read.
 */
function twoPagePdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
        ."4 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/**
 * The list endpoint's URL for one document of one model.
 */
function listUrl(Model $model, string $key = 'default'): string
{
    return route('pindle.annotations.index', [
        'annotatable_type' => $model->getMorphClass(),
        'annotatable_id' => (string) $model->getKey(),
        'document_key' => $key,
    ]);
}

/**
 * A well-formed create payload, for tests that are about something else.
 *
 * @return array<string, mixed>
 */
function annotationPayload(Model $model, string $key = 'default'): array
{
    return [
        'annotatable_type' => $model->getMorphClass(),
        'annotatable_id' => (string) $model->getKey(),
        'document_key' => $key,
        'page' => 1,
        'type' => 'highlight',
        'rects' => [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]],
    ];
}

/**
 * A comment on an annotation, optionally answering another.
 */
function comment(Annotation $annotation, string $body, ?Comment $parent = null, ?Model $author = null): Comment
{
    /** @var Comment $comment */
    $comment = Comment::query()->create([
        'annotation_id' => $annotation->id,
        'parent_id' => $parent?->id,
        'author_type' => $author?->getMorphClass() ?? 'user',
        'author_id' => (string) ($author?->getKey() ?? '1'),
        'body' => $body,
    ]);

    return $comment;
}

/**
 * The hash of a model's document, or a placeholder when it has none -- a test
 * about scopes should not have to put a PDF on a disk first.
 */
function documentHash(Model $model, string $key = 'default'): string
{
    $document = method_exists($model, 'pindleDocument') ? $model->pindleDocument($key) : null;

    return $document?->hash() ?? str_repeat('0', 64);
}
