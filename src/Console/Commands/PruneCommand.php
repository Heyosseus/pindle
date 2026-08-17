<?php

declare(strict_types=1);

namespace Pindle\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Pindle\Pindle;

/**
 * Forget what was deleted long enough ago.
 *
 * Deleting an annotation soft-deletes it, which is what keeps "who withdrew the
 * objection, and when" answerable after the objection is gone. That answer stops
 * being worth its rows eventually, and this is where it stops.
 *
 * Comments go first and by hand rather than by cascade: the foreign key cascades
 * on a real delete, but a soft-deleted comment under a still-live annotation has
 * no cascade to ride and would otherwise never be collected.
 */
final class PruneCommand extends Command
{
    protected $signature = 'pindle:prune {--days= : Override the configured retention window}';

    protected $description = 'Permanently remove annotations and comments deleted beyond the retention window';

    public function handle(): int
    {
        $days = $this->days();

        if ($days < 1) {
            $this->components->error('The retention window must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $comments = $this->count(Pindle::commentModel()::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete());

        $annotations = $this->count(Pindle::annotationModel()::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete());

        $this->components->info(sprintf(
            'Pruned %d annotation(s) and %d comment(s) deleted before %s.',
            $annotations,
            $comments,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }

    /** How many rows a force delete actually removed. */
    private function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }

    private function days(): int
    {
        $override = $this->option('days');

        if (is_string($override) && is_numeric($override)) {
            return (int) $override;
        }

        $configured = config('pindle.pruning.retain_days');

        return is_numeric($configured) ? (int) $configured : 90;
    }
}
