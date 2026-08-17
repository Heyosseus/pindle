<?php

declare(strict_types=1);

namespace Pindle;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Pindle\Console\Commands\PruneCommand;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\AttributeDocumentResolver;
use Pindle\Documents\ChainDocumentResolver;
use Pindle\Policies\AnnotationPolicy;

final class PindleServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pindle.php', 'pindle');

        // Bound transient rather than shared. A resolver hands back documents that
        // memoise their own hash, and a hash memoised for the life of the container
        // is a hash that survives the file being replaced -- which is the one event
        // orphan detection exists to notice.
        $this->app->bind(DocumentResolver::class, fn (Application $app): DocumentResolver => new ChainDocumentResolver([
            $app->make(AttributeDocumentResolver::class),
        ]));

        $this->app->singleton(AnnotationPolicy::class);
    }

    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class]);

            $this->publishes([
                __DIR__.'/../config/pindle.php' => $this->app->configPath('pindle.php'),
            ], 'pindle-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'pindle-migrations');
        }
    }

    /**
     * Put the prune command on the scheduler, unless the application would rather
     * schedule it itself.
     *
     * A null or blank cadence means exactly that: Pindle registers nothing and the
     * application wires the command into its own scheduler.
     */
    private function registerSchedule(): void
    {
        $config = $this->app->make(Repository::class);

        if (! (bool) $config->get('pindle.pruning.enabled', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($config): void {
            $cadence = $config->get('pindle.pruning.schedule', 'daily');

            if (! is_string($cadence) || $cadence === '') {
                return;
            }

            $recognised = ['hourly', 'daily', 'twiceDaily', 'weekly', 'monthly'];

            $schedule->command('pindle:prune')
                ->withoutOverlapping()
                ->{in_array($cadence, $recognised, true) ? $cadence : 'daily'}();
        });
    }

    private function enabled(): bool
    {
        return (bool) $this->app->make(Repository::class)->get('pindle.enabled', true);
    }
}
