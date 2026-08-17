<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The prune as the scheduler records it, or null when nothing was scheduled.
 *
 * The cadence is read when the scheduler is first resolved rather than when the
 * package boots, which is what lets each of these set one and then look.
 */
function scheduledPrune(): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command ?? '', 'pindle:prune')) {
            return $event;
        }
    }

    return null;
}

it('schedules the prune daily when the application asked for it', function (): void {
    expect(scheduledPrune()?->expression)->toBe('0 0 * * *');
});

it('never starts a second prune on top of one still running', function (): void {
    expect(scheduledPrune()?->withoutOverlapping)->toBeTrue();
});

it('takes the cadence the application configured', function (): void {
    config()->set('pindle.pruning.schedule', 'weekly');

    expect(scheduledPrune()?->expression)->toBe('0 0 * * 0');
});

it('falls back to daily when the cadence names nothing the scheduler knows', function (): void {
    config()->set('pindle.pruning.schedule', 'fortnightly');

    expect(scheduledPrune()?->expression)->toBe('0 0 * * *');
});

it('stays out of the scheduler when the application would rather do it itself', function (): void {
    config()->set('pindle.pruning.schedule');

    expect(scheduledPrune())->toBeNull();
});
