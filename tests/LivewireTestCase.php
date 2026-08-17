<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ViewErrorBag;
use Override;

/**
 * Boots Livewire, and only for the tests that are about the Livewire wrapper.
 *
 * The wrapper is optional. Keeping its provider out of the base case is what
 * proves the claim -- everything else in the suite runs with no Livewire at all,
 * and passes.
 */
abstract class LivewireTestCase extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['session']->start();
        $this->app['view']->share('errors', new ViewErrorBag);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }
}
