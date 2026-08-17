<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Pindle\Enums\AnnotationType;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Tests\DisabledTestCase;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\ScheduledTestCase;
use Pindle\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch and the pruning schedule are both read while the provider
 * boots, so the tests that prove them boot differently configured applications.
 */
uses(DisabledTestCase::class)->in('Disabled');
uses(ScheduledTestCase::class)->in('Scheduled');

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
