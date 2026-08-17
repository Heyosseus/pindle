<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pindle\Models\Comment;
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

it('posts a comment on an annotation', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), [
            'body' => 'This total does not match the purchase order.',
        ])
        ->assertCreated()
        ->assertJsonPath('body', 'This total does not match the purchase order.')
        ->assertJsonPath('parent_id', null)
        ->assertJsonPath('author.id', (string) $this->reviewer->id);
});

it('answers a comment, one level deep', function (): void {
    $root = comment($this->annotation, 'This total does not match.');

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), [
            'body' => 'Corrected in revision B.',
            'parent_id' => $root->id,
        ])
        ->assertCreated()
        ->assertJsonPath('parent_id', $root->id);
});

it('flattens a reply to a reply onto the same parent', function (): void {
    $root = comment($this->annotation, 'This total does not match.');
    $reply = comment($this->annotation, 'Corrected in revision B.', $root);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), [
            'body' => 'Thanks.',
            'parent_id' => $reply->id,
        ])
        ->assertCreated()
        ->assertJsonPath('parent_id', $root->id);
});

it('ignores a parent belonging to a different annotation', function (): void {
    $elsewhere = annotate($this->invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), [
            'body' => 'Thanks.',
            'parent_id' => comment($elsewhere, 'Elsewhere.')->id,
        ])
        ->assertCreated()
        ->assertJsonPath('parent_id', null);
});

it('corrects something already said', function (): void {
    $comment = comment($this->annotation, 'This total does not match.');

    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.comments.update', $comment->id), ['body' => 'Withdrawn - my error.'])
        ->assertOk()
        ->assertJsonPath('body', 'Withdrawn - my error.');
});

it('takes a comment down without losing the audit trail', function (): void {
    $comment = comment($this->annotation, 'This total does not match.');

    $this->actingAs($this->reviewer)
        ->deleteJson(route('pindle.comments.destroy', $comment->id))
        ->assertNoContent();

    expect(Comment::query()->count())->toBe(0)
        ->and(Comment::query()->withTrashed()->count())->toBe(1);
});

it('stores a body exactly as it was typed, markup and all', function (): void {
    $body = '<script>alert(1)</script> **not bold**';

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => $body])
        ->assertCreated()
        ->assertJsonPath('body', $body);

    expect(Comment::query()->firstOrFail()->body)->toBe($body);
});

it('refuses a comment longer than the configured limit', function (): void {
    config()->set('pindle.comments.max_length', 20);

    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), [
            'body' => str_repeat('a', 21),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('refuses an empty comment', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', $this->annotation->id), ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('answers with nothing found for a comment that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->patchJson(route('pindle.comments.update', 'not-an-id'), ['body' => 'Hello.'])
        ->assertNotFound();
});

it('answers with nothing found when commenting on an annotation that is not there', function (): void {
    $this->actingAs($this->reviewer)
        ->postJson(route('pindle.comments.store', 'not-an-id'), ['body' => 'Hello.'])
        ->assertNotFound();
});
