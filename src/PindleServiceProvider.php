<?php

declare(strict_types=1);

namespace Pindle;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
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

        $this->registerRoutes();

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
     * The JSON API and the document stream, under the application's own stack.
     *
     * The middleware is whatever was configured and nothing more. Pindle does not
     * append a guard of its own the way a dashboard package would, because every
     * endpoint here already asks the application's policy about the model that
     * owns the document -- an extra layer would be a second answer to a question
     * that already has one.
     */
    private function registerRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        Route::group([
            'domain' => $config->get('pindle.routes.domain'),
            'prefix' => $config->get('pindle.routes.prefix', 'pindle'),
            'middleware' => $this->middleware($config),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/pindle.php');
        });
    }

    /**
     * @return list<string>
     */
    private function middleware(Repository $config): array
    {
        $configured = $config->get('pindle.routes.middleware', []);

        if (! is_array($configured)) {
            return [];
        }

        $stack = [];

        foreach ($configured as $middleware) {
            if (is_string($middleware)) {
                $stack[] = $middleware;
            }
        }

        return $stack;
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
