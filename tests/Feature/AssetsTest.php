<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Pindle\Support\Assets;
use Pindle\Support\Bundle;

/*
 * What `@pindleScripts` emits, and how long it remembers having emitted it.
 *
 * The lifetime is the part with teeth. "Once per request" held by a static is
 * "once per worker" under Octane, FrankenPHP or any other runtime where the
 * process outlives the response -- the first visitor gets the viewer and
 * everybody after them gets a blank rectangle, on a server that reports no
 * errors at all.
 */

it('emits the stylesheet and the script, once', function (): void {
    $html = Blade::render('@pindleScripts @pindleScripts');

    expect(substr_count($html, '<script'))->toBe(1)
        ->and(substr_count($html, '<link'))->toBe(1);
});

it('emits again for the next request in a long-running worker', function (): void {
    expect(Blade::render('@pindleScripts'))->toContain('<script');

    // Precisely what Octane does between requests. A static flag would survive
    // this; a scoped binding does not, which is the whole reason it is one.
    app()->forgetScopedInstances();

    expect(Blade::render('@pindleScripts'))->toContain('<script');
});

it('stamps every asset with the bundle version', function (): void {
    $version = Bundle::version();

    expect(app(Assets::class)->url('pindle.js'))->toEndWith('?id='.$version)
        ->and(Blade::render('@pindleScripts'))->toContain('?id='.$version);
});

it('versions the bundle by what is in it', function (): void {
    expect(Bundle::version())
        ->toBe(Bundle::version())
        ->toMatch('/^[0-9a-f]{12}$/');
});

it('reports nothing published when nothing has been', function (): void {
    // Testbench's skeleton has no `public/vendor/pindle`, which is the same
    // state as an application that installed the package and forgot the assets.
    expect(Bundle::publishedVersion())->toBeNull()
        ->and(Bundle::isPublishedCurrent())->toBeFalse()
        ->and(Bundle::missing())->toBe(Bundle::FILES);
});
