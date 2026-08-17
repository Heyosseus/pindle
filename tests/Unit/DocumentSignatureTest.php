<?php

declare(strict_types=1);

use Pindle\Documents\DocumentSignature;

it('survives a round trip through its token', function (): void {
    $payload = new DocumentSignature('invoice', '42', 'delivery_note', '7');

    expect(DocumentSignature::decode($payload->encode()))->toEqual($payload);
});

it('carries no user when the link was minted for nobody', function (): void {
    $payload = new DocumentSignature('invoice', '42', 'default', null);

    expect(DocumentSignature::decode($payload->encode())?->userId)->toBeNull()
        ->and($payload->belongsTo(null))->toBeTrue();
});

it('reads nothing out of a token that is not base64', function (): void {
    expect(DocumentSignature::decode('!!!not base64!!!'))->toBeNull();
});

it('reads nothing out of a token that is not json', function (): void {
    expect(DocumentSignature::decode(rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=')))->toBeNull();
});

it('reads nothing out of a token whose json is not an object', function (): void {
    expect(DocumentSignature::decode(rtrim(strtr(base64_encode('"a string"'), '+/', '-_'), '=')))->toBeNull();
});

it('reads nothing out of a token missing what it has to say', function (): void {
    $token = rtrim(strtr(base64_encode((string) json_encode(['t' => 'invoice'])), '+/', '-_'), '=');

    expect(DocumentSignature::decode($token))->toBeNull();
});

it('treats a non-string user id as no user at all', function (): void {
    $token = rtrim(strtr(base64_encode((string) json_encode([
        't' => 'invoice', 'i' => '1', 'k' => 'default', 'u' => 42,
    ])), '+/', '-_'), '=');

    expect(DocumentSignature::decode($token)?->userId)->toBeNull();
});

it('resolves nothing when the morph names something that is not a model', function (): void {
    expect((new DocumentSignature('DateTimeImmutable', '1', 'default', null))->annotatable())->toBeNull()
        ->and((new DocumentSignature('Not\\A\\Class', '1', 'default', null))->annotatable())->toBeNull();
});
