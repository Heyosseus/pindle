<?php

declare(strict_types=1);

namespace Pindle\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Pindle\Review\ReviewExport;

/**
 * ```
 * php artisan pindle:export "App\Models\Invoice" 4471 --format=md
 * ```
 *
 * "What did legal actually say about this contract?" answered from a terminal,
 * a cron job or a queue, without opening the viewer. It is the shortest proof
 * that the annotations are yours: nothing here talks to the front end.
 */
final class ExportCommand extends Command
{
    protected $signature = 'pindle:export
        {model : The annotatable class or morph alias}
        {id : Its key}
        {--document=default : Which document on that model}
        {--format=md : md or json}
        {--path= : Write to this file instead of the screen}';

    protected $description = 'Export a document\'s annotations and comments as markdown or JSON';

    public function handle(): int
    {
        $annotatable = $this->annotatable();

        if (! $annotatable instanceof Model) {
            $this->components->error('No such record.');

            return self::FAILURE;
        }

        $format = strtolower($this->text('format', option: true));

        if (! in_array($format, ['md', 'markdown', 'json'], true)) {
            $this->components->error('The format must be md or json.');

            return self::FAILURE;
        }

        $export = new ReviewExport($annotatable, $this->text('document', option: true) ?: 'default');

        $output = $format === 'json' ? $export->toJson() : $export->toMarkdown();

        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            File::put($path, $output);

            $this->components->info('Written to '.$path.'.');

            return self::SUCCESS;
        }

        $this->line($output);

        return self::SUCCESS;
    }

    /**
     * The record named on the command line.
     *
     * Resolved through the morph map first, so an application that aliases its
     * models can name the alias rather than the class -- and so that a typo
     * cannot name a class Pindle then instantiates.
     */
    private function annotatable(): ?Model
    {
        $name = $this->text('model');

        $class = Relation::getMorphedModel($name) ?? $name;

        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $record = $class::query()->find($this->text('id'));

        return $record instanceof Model ? $record : null;
    }

    /** An argument or an option as the string it is meant to be. */
    private function text(string $name, bool $option = false): string
    {
        $value = $option ? $this->option($name) : $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
