<?php

declare(strict_types=1);

namespace Pindle\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Pindle\Pindle;
use Throwable;

/**
 * The whole install, in one command.
 *
 * Publishing config, migrations and assets by hand is four commands with tag
 * names nobody remembers, and forgetting the third produces a viewer that loads
 * a page and then nothing -- no error, no log line, just an empty rectangle.
 * This does all of it and then says, in order, the three things it cannot do for
 * you.
 */
final class InstallCommand extends Command
{
    protected $signature = 'pindle:install
        {--force : Overwrite files that are already published}';

    protected $description = 'Publish Pindle\'s config, migrations and viewer, and run its migrations';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>Pindle</> — PDF review inside your app');
        $this->newLine();

        $force = (bool) $this->option('force');

        $this->publish('pindle-config', 'Configuration', $force);
        $this->publish('pindle-migrations', 'Migrations', $force);

        // Always forced. The assets are compiled output rather than something
        // anyone edits, and a publish that politely declines to overwrite them
        // is how an upgraded package ends up serving last month's viewer.
        $this->publish('pindle-assets', 'Viewer', true);

        $this->migrate();

        $this->next();

        return self::SUCCESS;
    }

    private function publish(string $tag, string $label, bool $force): void
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => $tag,
            '--force' => $force ?: null,
        ]));

        $this->components->task('  '.$label.' published');
    }

    /**
     * Run the migrations, once the developer says so.
     *
     * Asked rather than assumed: `migrate` on a machine pointed at the wrong
     * database is a bad afternoon, and an install command is exactly when
     * somebody is least sure which database that is. Skipping is fine and says
     * so.
     */
    private function migrate(): void
    {
        if ($this->alreadyMigrated()) {
            $this->components->task('  Tables already present');

            return;
        }

        if (! $this->confirm('  Run the migrations now?', true)) {
            $this->components->warn('Skipped. Run php artisan migrate when you are ready.');

            return;
        }

        $this->call('migrate');
    }

    private function alreadyMigrated(): bool
    {
        try {
            return Schema::hasTable((new (Pindle::annotationModel()))->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The three things an install cannot do for you, in the order they are done.
     */
    private function next(): void
    {
        $this->newLine();
        $this->line('  <options=bold>Three steps left.</>');
        $this->newLine();

        $steps = [
            ['Put the trait on the model that owns the PDF', 'use Pindle\Concerns\HasAnnotations;'],
            ['Make sure that model has a policy', 'php artisan make:policy InvoicePolicy --model=Invoice'],
            ['Render the viewer, with the assets loaded', '@pindleScripts'."\n".'<x-pindle::viewer :for="$invoice" />'],
        ];

        foreach ($steps as $index => [$title, $code]) {
            $this->line(sprintf('  <fg=yellow>%d.</> %s', $index + 1, $title));

            foreach (explode("\n", $code) as $line) {
                $this->line('     <fg=gray>'.$line.'</>');
            }

            $this->newLine();
        }

        $this->line('  Documents are read from the <options=bold>'.$this->disk().'</> disk.');
        $this->line('  Keep it private. Pindle streams documents through a signed, expiring');
        $this->line('  route that asks your policy on every request, and never hands out a disk URL.');
        $this->newLine();
        $this->line('  Check it over with <options=bold>php artisan pindle:doctor</>.');
        $this->newLine();
    }

    private function disk(): string
    {
        $disk = config('pindle.documents.disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
