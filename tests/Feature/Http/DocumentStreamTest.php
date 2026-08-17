<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pindle\Documents\DocumentSignature;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Invoice::class, InvoicePolicy::class);

    $this->body = '%PDF-1.7 '.str_repeat('x', 500);

    $this->invoice = invoiceWithDocument($this->body);
    $this->invoice->update(['tenant_id' => 1]);

    $this->reviewer = User::query()->create(['name' => 'Reviewer', 'tenant_id' => 1]);
});

/** The streamed body, which only exists once the response has been sent. */
function streamed(Illuminate\Testing\TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

it('streams the bytes inline to whoever the link was minted for', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Disposition', 'inline; filename="1.pdf"')
        ->assertHeader('Content-Length', (string) strlen($this->body));

    expect(streamed($response))->toBe($this->body);
});

it('never lets a shared cache keep the bytes', function (): void {
    $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('answers a range with just that slice', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->withHeaders(['Range' => 'bytes=0-9'])
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertStatus(206)
        ->assertHeader('Content-Range', sprintf('bytes 0-9/%d', strlen($this->body)))
        ->assertHeader('Content-Length', '10');

    expect(streamed($response))->toBe(substr($this->body, 0, 10));
});

it('answers an open-ended range with the rest of the file', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->withHeaders(['Range' => 'bytes=500-'])
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertStatus(206);

    expect(streamed($response))->toBe(substr($this->body, 500));
});

it('answers a suffix range, which is how a reader finds the trailer', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->withHeaders(['Range' => 'bytes=-20'])
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertStatus(206);

    expect(streamed($response))->toBe(substr($this->body, -20));
});

it('says what would have been acceptable when a range is not', function (): void {
    $this->actingAs($this->reviewer)
        ->withHeaders(['Range' => 'bytes=99999-100000'])
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertStatus(416)
        ->assertHeader('Content-Range', sprintf('bytes */%d', strlen($this->body)));
});

it('sends the whole document when the range header is not one it honours', function (): void {
    $response = $this->actingAs($this->reviewer)
        ->withHeaders(['Range' => 'bytes=0-9,20-29'])
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertOk();

    expect(streamed($response))->toBe($this->body);
});

it('refuses a link that was never signed', function (): void {
    $token = DocumentSignature::for($this->invoice, 'default', $this->reviewer)->encode();

    $this->actingAs($this->reviewer)
        ->get(route('pindle.documents.show', $token))
        ->assertForbidden();
});

it('refuses a link once it has expired', function (): void {
    $url = DocumentSignature::url($this->invoice, 'default', $this->reviewer);

    $this->travel(6)->minutes();

    $this->actingAs($this->reviewer)->get($url)->assertForbidden();
});

it('refuses a link whose payload was edited', function (): void {
    $url = DocumentSignature::url($this->invoice, 'default', $this->reviewer);

    $tampered = str_replace(
        DocumentSignature::for($this->invoice, 'default', $this->reviewer)->encode(),
        DocumentSignature::for($this->invoice, 'delivery_note', $this->reviewer)->encode(),
        $url,
    );

    $this->actingAs($this->reviewer)->get($tampered)->assertForbidden();
});

it('refuses a valid link held by somebody it was not minted for', function (): void {
    $colleague = User::query()->create(['name' => 'Colleague', 'tenant_id' => 1]);

    $url = DocumentSignature::url($this->invoice, 'default', $this->reviewer);

    // The colleague can see this invoice perfectly well. The link is still not
    // theirs, and a signed URL is not a bearer token.
    $this->actingAs($colleague)->get($url)->assertForbidden();
});

it('refuses a still-valid link held by somebody who has lost access', function (): void {
    $url = DocumentSignature::url($this->invoice, 'default', $this->reviewer);

    // The signature is untouched and unexpired; only the policy's answer changed.
    $this->reviewer->update(['tenant_id' => 2]);

    $this->actingAs($this->reviewer)->get($url)->assertForbidden();
});

it('answers with nothing found for a document that is not on the disk', function (): void {
    Storage::disk('documents')->delete('invoices/1.pdf');

    $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'default', $this->reviewer))
        ->assertNotFound();
});

it('answers with nothing found for a document key the model does not carry', function (): void {
    $this->actingAs($this->reviewer)
        ->get(DocumentSignature::url($this->invoice, 'appendix', $this->reviewer))
        ->assertNotFound();
});

it('answers with nothing found for a payload that is not a payload', function (): void {
    $this->actingAs($this->reviewer)
        ->get(URL::temporarySignedRoute('pindle.documents.show', now()->addMinutes(5), [
            'document' => 'not-a-token',
        ]))
        ->assertNotFound();
});

it('answers with nothing found when the payload names a model that is gone', function (): void {
    $url = DocumentSignature::url($this->invoice, 'default', $this->reviewer);

    $this->invoice->delete();

    $this->actingAs($this->reviewer)->get($url)->assertNotFound();
});
