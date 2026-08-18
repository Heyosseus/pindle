<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Pindle\Models\Annotation;

/*
 * The two commands an application runs and never thinks about again: one to get
 * installed, one to find out that the install has since drifted.
 */

/*
 * The install command publishes into the skeleton, and the skeleton is a real
 * directory that outlives the test. Left behind, a published migration is run a
 * second time by the next test that migrates and fails on a table it created
 * itself -- so both are cleared either side.
 */
beforeEach(function (): void {
    clearPublishedAssets();
    clearPublishedMigrations();
});

afterEach(function (): void {
    clearPublishedAssets();
    clearPublishedMigrations();
});

function clearPublishedMigrations(): void
{
    foreach ((array) glob(database_path('migrations/*pindle*')) as $file) {
        if (is_string($file)) {
            unlink($file);
        }
    }
}

it('reports a healthy installation and exits zero', function (): void {
    publishAssets();

    $this->artisan('pindle:doctor')
        ->expectsOutputToContain('Published and current')
        ->assertSuccessful();
});

it('exits non-zero so a deploy stops on a broken install', function (): void {
    // Nothing published, which is a viewer that cannot load.
    $this->artisan('pindle:doctor')
        ->expectsOutputToContain('check(s) failed')
        ->assertFailed();
});

it('prints the command that fixes what it found', function (): void {
    $this->artisan('pindle:doctor')
        ->expectsOutputToContain('vendor:publish --tag=pindle-assets')
        ->assertFailed();
});

it('says everything checks out only when nothing warned either', function (): void {
    publishAssets();

    config()->set('pindle.routes.middleware', ['web', 'auth']);
    config()->set('pindle.routes.throttle', '60,1');
    config()->set('filesystems.disks.private', ['driver' => 'local', 'root' => storage_path('app/private')]);
    config()->set('pindle.documents.disk', 'private');

    $this->artisan('pindle:doctor')
        ->expectsOutputToContain('Everything checks out')
        ->assertSuccessful();
});

it('publishes everything and names the three steps it cannot do for you', function (): void {
    $this->artisan('pindle:install')
        ->expectsOutputToContain('Three steps left')
        ->expectsOutputToContain('HasAnnotations')
        ->expectsOutputToContain('make:policy')
        ->expectsOutputToContain('@pindleScripts')
        ->expectsOutputToContain('pindle:doctor')
        ->assertSuccessful();

    // The viewer is published without asking, because it is compiled output
    // rather than anything an application edits.
    expect(is_file(public_path('vendor/pindle/pindle.js')))->toBeTrue();
});

it('does not offer to migrate over tables that are already there', function (): void {
    $this->artisan('pindle:install')
        ->expectsOutputToContain('Tables already present')
        ->assertSuccessful();
});

it('offers to migrate when the tables are missing, and takes no for an answer', function (): void {
    Schema::drop('pindle_comments');
    Schema::drop('pindle_annotations');

    $this->artisan('pindle:install')
        ->expectsConfirmation('  Run the migrations now?', 'no')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect(Schema::hasTable('pindle_annotations'))->toBeFalse();
});

it('runs the migrations when told to', function (): void {
    Schema::drop('pindle_comments');
    Schema::drop('pindle_annotations');

    $this->artisan('pindle:install')
        ->expectsConfirmation('  Run the migrations now?', 'yes')
        ->assertSuccessful();

    expect(Schema::hasTable('pindle_annotations'))->toBeTrue()
        ->and(Annotation::query()->count())->toBe(0);
});

it('names the disk documents are read from, so a public one is noticed early', function (): void {
    config()->set('pindle.documents.disk', 'contracts');

    $this->artisan('pindle:install')
        ->expectsOutputToContain('contracts')
        ->expectsOutputToContain('Keep it private.')
        ->assertSuccessful();
});

it('installs against a database that is not there yet', function (): void {
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/nowhere/at/all.sqlite']);
    config()->set('database.default', 'broken');

    // Publishing does not need a database, and a first deploy runs this before
    // one exists. Unable to tell whether the tables are there, it offers.
    try {
        $this->artisan('pindle:install')
            ->expectsConfirmation('  Run the migrations now?', 'no')
            ->expectsOutputToContain('Three steps left')
            ->assertSuccessful();
    } finally {
        config()->set('database.default', 'testing');
    }
});
