<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Pindle\Diagnostics\Diagnostics;
use Pindle\Diagnostics\Severity;
use Pindle\Models\Annotation;
use Pindle\Tests\Fixtures\Invoice;
use Pindle\Tests\Fixtures\InvoicePolicy;
use Pindle\Tests\Fixtures\User;

/*
 * The two failures nobody notices.
 *
 * Documents served off a public disk work perfectly, right up to the moment
 * somebody discovers the URLs were never guarded. A viewer bundle left stale by
 * an upgrade works perfectly for everyone whose browser cached it and for nobody
 * else. Neither throws and neither logs, which is the entire argument for a
 * command that goes looking.
 */

beforeEach(function (): void {
    clearPublishedAssets();
});

afterEach(function (): void {
    clearPublishedAssets();
});

/** One check's result, by name. */
function diagnosis(string $check): Pindle\Diagnostics\Diagnosis
{
    foreach (Diagnostics::run() as $diagnosis) {
        if ($diagnosis->check === $check) {
            return $diagnosis;
        }
    }

    throw new RuntimeException("No check named {$check}.");
}

it('passes a documents disk that is not readable from outside', function (): void {
    config()->set('filesystems.disks.private', ['driver' => 'local', 'root' => storage_path('app/private')]);
    config()->set('pindle.documents.disk', 'private');

    expect(diagnosis('Documents disk')->severity)->toBe(Severity::Pass);
});

it('says so rather than guessing about a disk built at runtime', function (): void {
    // The suite's own disk is a Storage::fake(), which is registered on the
    // manager and never written to config -- as are Storage::build() and
    // Storage::extend() in real applications. There is nothing to read, and
    // claiming to have checked it would be worse than admitting that.
    expect(diagnosis('Documents disk'))
        ->severity->toBe(Severity::Warn)
        ->detail->toContain('registered at runtime');
});

it('fails a documents disk whose contents are public', function (): void {
    config()->set('filesystems.disks.leaky', ['driver' => 'local', 'root' => '/tmp/leaky', 'visibility' => 'public']);
    config()->set('pindle.documents.disk', 'leaky');

    $disk = diagnosis('Documents disk');

    expect($disk->severity)->toBe(Severity::Fail)
        ->and($disk->detail)->toContain('visibility is public')
        ->and($disk->fix)->toContain('PINDLE_DISK');
});

it('fails a documents disk rooted inside the public directory', function (): void {
    config()->set('filesystems.disks.exposed', ['driver' => 'local', 'root' => public_path('documents')]);
    config()->set('pindle.documents.disk', 'exposed');

    if (! is_dir(public_path('documents'))) {
        mkdir(public_path('documents'), recursive: true);
    }

    expect(diagnosis('Documents disk')->detail)->toContain('inside the public directory');
});

it('fails a documents disk that does not exist', function (): void {
    config()->set('pindle.documents.disk', 'imaginary');

    expect(diagnosis('Documents disk'))
        ->severity->toBe(Severity::Fail)
        ->detail->toContain('not defined');
});

it('fails when the viewer has not been published', function (): void {
    // Testbench's skeleton has no public/vendor/pindle, which is exactly the
    // state of an application that installed the package and stopped there.
    expect(diagnosis('Viewer assets'))
        ->severity->toBe(Severity::Fail)
        ->fix->toContain('vendor:publish');
});

it('fails when the published viewer is older than the installed package', function (): void {
    $published = public_path('vendor/pindle');

    mkdir($published, recursive: true);

    foreach (['pindle.js', 'pindle.css', 'pdfium.wasm'] as $file) {
        file_put_contents($published.'/'.$file, 'what last month shipped');
    }

    $assets = diagnosis('Viewer assets');

    expect($assets->severity)->toBe(Severity::Fail)
        ->and($assets->detail)->toContain('Stale')
        ->and($assets->fix)->toContain('--force');
});

it('fails when only some of the viewer was published', function (): void {
    $published = public_path('vendor/pindle');

    mkdir($published, recursive: true);
    file_put_contents($published.'/pindle.js', 'half an install');

    expect(diagnosis('Viewer assets')->detail)->toContain('missing pindle.css, pdfium.wasm');
});

it('passes a published viewer that matches the package', function (): void {
    publishAssets();

    expect(diagnosis('Viewer assets')->severity)->toBe(Severity::Pass);
});

it('passes migrations that have run and fails ones that have not', function (): void {
    expect(diagnosis('Migrations')->severity)->toBe(Severity::Pass);

    Schema::drop('pindle_comments');
    Schema::drop('pindle_annotations');

    expect(diagnosis('Migrations'))
        ->severity->toBe(Severity::Fail)
        ->fix->toContain('migrate');
});

it('warns when nothing establishes who is asking', function (): void {
    // The suite drops 'auth' so requests reach the policy rather than a login
    // route Testbench has no application to provide; the shipped default has it.
    config()->set('pindle.routes.middleware', ['web', 'auth']);

    expect(diagnosis('Route middleware')->severity)->toBe(Severity::Pass);

    config()->set('pindle.routes.middleware', ['web']);

    expect(diagnosis('Route middleware'))
        ->severity->toBe(Severity::Warn)
        ->detail->toContain('Nothing authenticating');

    config()->set('pindle.routes.middleware', []);

    expect(diagnosis('Route middleware')->detail)->toContain('No middleware at all');
});

it('warns when the write endpoints are not rate limited', function (): void {
    // Testbench's own route config wins over the shipped file, so the limit the
    // package ships with has to be put back before it can be read here. That the
    // shipped default is a limit at all is asserted in the throttle test.
    config()->set('pindle.routes.throttle', '60,1');

    expect(diagnosis('Write rate limit'))
        ->severity->toBe(Severity::Pass)
        ->detail->toContain('throttle:60,1');

    config(['pindle.routes.throttle' => null]);

    expect(diagnosis('Write rate limit'))
        ->severity->toBe(Severity::Warn)
        ->fix->toContain('pindle.routes.throttle');
});

it('fails a signed url lifetime that expires on arrival', function (): void {
    config()->set('pindle.documents.url_ttl', 0);

    expect(diagnosis('Document URLs')->severity)->toBe(Severity::Fail);
});

it('warns about a signed url worth stealing', function (): void {
    config()->set('pindle.documents.url_ttl', 86400);

    expect(diagnosis('Document URLs'))
        ->severity->toBe(Severity::Warn)
        ->detail->toContain('86400 seconds');
});

it('says there is nothing to check before anything is annotated', function (): void {
    expect(diagnosis('Policies')->detail)->toContain('Nothing annotated yet');
});

it('fails a model that has been annotated but has no policy', function (): void {
    Annotation::factory()->on(invoiceWithDocument())->create();

    expect(diagnosis('Policies'))
        ->severity->toBe(Severity::Fail)
        ->detail->toContain('every request about them is denied');

    Gate::policy(Invoice::class, InvoicePolicy::class);

    expect(diagnosis('Policies')->severity)->toBe(Severity::Pass);
});

it('warns about a model annotated without the trait', function (): void {
    // A policy, so the harsher check passes, but no HasAnnotations -- the state
    // an application reaches by writing the morph columns itself.
    Gate::policy(User::class, InvoicePolicy::class);

    Annotation::factory()->create(['annotatable_type' => User::class]);

    expect(diagnosis('Policies'))
        ->severity->toBe(Severity::Warn)
        ->detail->toContain('not using the trait')
        ->fix->toContain('HasAnnotations');
});

it('warns when the package is switched off', function (): void {
    expect(diagnosis('Package')->severity)->toBe(Severity::Pass);

    config()->set('pindle.enabled', false);

    expect(diagnosis('Package'))
        ->severity->toBe(Severity::Warn)
        ->fix->toContain('PINDLE_ENABLED');
});

it('calls an installation healthy only when nothing is broken', function (): void {
    // The viewer is unpublished in a fresh skeleton, which is a failure.
    expect(Diagnostics::isHealthy())->toBeFalse();

    publishAssets();

    // Still warns about the missing 'auth' this suite drops, and healthy is
    // about what is broken rather than about what is merely worth mentioning.
    expect(Diagnostics::isHealthy())->toBeTrue();
});

it('ignores marks left behind by a model that no longer exists', function (): void {
    // The factory leaves an annotation deliberately unattached, whose morph type
    // names no class -- the same shape a renamed or deleted model leaves behind.
    Annotation::factory()->create();

    expect(diagnosis('Policies')->severity)->toBe(Severity::Pass);
});

it('says so plainly when the database cannot be reached', function (): void {
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/nowhere/at/all.sqlite']);
    config()->set('database.default', 'broken');

    // The state of a first deploy: the package is installed, the database is
    // not there yet, and the useful answer is which of those it is. Restored
    // before the assertions so the case can still tear itself down.
    try {
        $migrations = diagnosis('Migrations');
        $policies = diagnosis('Policies');
    } finally {
        config()->set('database.default', 'testing');
    }

    expect($migrations)
        ->severity->toBe(Severity::Fail)
        ->detail->toContain('Could not reach the database');

    expect($policies)
        ->severity->toBe(Severity::Warn)
        ->detail->toContain('Could not read the annotations table');
});
