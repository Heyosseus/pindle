<?php

declare(strict_types=1);

use Pindle\Models\Annotation;
use Pindle\Models\Comment;

it('forgets what was deleted beyond the retention window', function (): void {
    $invoice = invoiceWithDocument();

    $old = annotate($invoice, [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);
    $recent = annotate($invoice, [['x1' => 5.0, 'y1' => 6.0, 'x2' => 7.0, 'y2' => 8.0]]);

    $old->delete();
    $recent->delete();

    $old->forceFill(['deleted_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('pindle:prune')->assertSuccessful();

    expect(Annotation::query()->withTrashed()->pluck('id')->all())->toBe([$recent->id]);
});

it('leaves what is not deleted alone, however old', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

    $this->artisan('pindle:prune')->assertSuccessful();

    expect(Annotation::query()->count())->toBe(1);
});

it('collects a soft-deleted comment under an annotation that is still live', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $comment = comment($annotation, 'Withdrawn.');
    $comment->delete();
    $comment->forceFill(['deleted_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('pindle:prune')->assertSuccessful();

    expect(Comment::query()->withTrashed()->count())->toBe(0)
        ->and(Annotation::query()->count())->toBe(1);
});

it('takes a window from the command line', function (): void {
    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->delete();
    $annotation->forceFill(['deleted_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('pindle:prune')->assertSuccessful();

    expect(Annotation::query()->withTrashed()->count())->toBe(1);

    $this->artisan('pindle:prune', ['--days' => '7'])->assertSuccessful();

    expect(Annotation::query()->withTrashed()->count())->toBe(0);
});

it('refuses a window that would prune what was deleted a moment ago', function (): void {
    $this->artisan('pindle:prune', ['--days' => '0'])->assertFailed();
});

it('falls back to ninety days when the configuration says nothing usable', function (): void {
    config()->set('pindle.pruning.retain_days', 'soon');

    $annotation = annotate(invoiceWithDocument(), [['x1' => 1.0, 'y1' => 2.0, 'x2' => 3.0, 'y2' => 4.0]]);

    $annotation->delete();
    $annotation->forceFill(['deleted_at' => now()->subDays(100)])->saveQuietly();

    $this->artisan('pindle:prune')->assertSuccessful();

    expect(Annotation::query()->withTrashed()->count())->toBe(0);
});
