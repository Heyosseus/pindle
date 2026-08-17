<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pindle\Documents\DocumentSignature;
use Pindle\Documents\DocumentStream;
use Pindle\Documents\PindleDocument;
use Pindle\Exceptions\DocumentUnreadable;
use Pindle\Http\Requests\StoreAnnotationRequest;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->invoice = invoiceWithDocument();
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
    $this->annotation = annotate($this->invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
});

it('answers with nothing found when the list names no model at all', function (): void {
    $this->actingAs($this->reviewer)
        ->getJson(route('pindle.annotations.index'))
        ->assertNotFound();
});

it('answers with nothing found when a create names no model at all', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), ['page' => 1, 'type' => 'highlight', 'rects' => []])
        ->assertNotFound();
});

it('answers with nothing found when an annotation outlives the model it was written on', function (): void {
    // Hard-deleted, so the morph points at a row that is not there. Without a
    // model there is no policy to ask, and nothing may proceed on a maybe.
    Invoice::query()->whereKey($this->invoice->id)->forceDelete();

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.annotations.update', $this->annotation->id), ['color' => '#fde047'])
        ->assertNotFound();

    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.annotations.destroy', $this->annotation->id))
        ->assertNotFound();

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => 'Hello.'])
        ->assertNotFound();
});

it('answers with nothing found when a comment outlives its document', function (): void {
    $comment = comment($this->annotation, 'Said before the invoice vanished.');

    Invoice::query()->whereKey($this->invoice->id)->forceDelete();

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.comments.update', $comment->id), ['body' => 'Edited.'])
        ->assertNotFound();

    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.comments.destroy', $comment->id))
        ->assertNotFound();
});

it('answers with nothing found when deleting an annotation that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.annotations.destroy', 'not-an-id'))
        ->assertNotFound();
});

it('answers with nothing found when deleting a comment that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.comments.destroy', 'not-an-id'))
        ->assertNotFound();
});

it('allows every type when the configured list is not a list', function (): void {
    config()->set('pindle.annotations.types', 'highlight');

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), array_merge(annotationPayload($this->invoice), ['type' => 'ink']))
        ->assertCreated();
});

it('allows every type when the configured list names nothing real', function (): void {
    config()->set('pindle.annotations.types', ['stamp', 'signature']);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.annotations.store'), annotationPayload($this->invoice))
        ->assertCreated();
});

it('reads no anchors out of a request that carries none', function (): void {
    $request = StoreAnnotationRequest::create('/', 'POST', ['rects' => 'not-an-array']);

    expect($request->anchors())->toBe([]);
});

it('reads no anchors out of a request whose anchors are not anchors', function (): void {
    $request = StoreAnnotationRequest::create('/', 'POST', ['rects' => ['not-a-rect']]);

    expect($request->anchors())->toBe([]);
});

it('refuses to stream a document that vanished between resolving and sending', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertOk();

    Storage::disk('documents')->delete('invoices/1.pdf');

    expect(fn () => $response->baseResponse->sendContent())->toThrow(DocumentUnreadable::class);
});

it('stops streaming when the document turns out to be shorter than it said', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertOk();

    Storage::disk('documents')->put('invoices/1.pdf', '%PDF');

    ob_start();
    $response->baseResponse->sendContent();

    expect((string) ob_get_clean())->toBe('%PDF');
});

it('reads past an offset it cannot seek to', function (): void {
    // A socket pair is the only readily available non-seekable stream, and the
    // non-seekable branch is exactly what an S3 disk takes.
    $pair = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    expect($pair)->toBeArray();

    [$read, $write] = $pair;

    fwrite($write, 'skip-me-then-KEEP');
    fclose($write);

    DocumentStream::skipTo($read, 13);

    expect(stream_get_contents($read))->toBe('KEEP');

    fclose($read);
});

it('leaves a stream alone when there is nothing to skip', function (): void {
    $stream = fopen('php://memory', 'r+');

    expect($stream)->toBeResource();

    fwrite($stream, 'KEEP');
    rewind($stream);

    DocumentStream::skipTo($stream, 0);

    expect(stream_get_contents($stream))->toBe('KEEP');

    fclose($stream);
});

it('has no size or bounds for a document with no path behind it', function (): void {
    expect((new PindleDocument('documents', 'nowhere.pdf'))->size())->toBe(0);
});
