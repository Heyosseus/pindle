<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;

/**
 * The master switch is read while the package boots, before any test body runs,
 * so proving it works means booting a differently configured application rather
 * than flipping a config value.
 */
abstract class DisabledTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pindle.enabled', false);
    }
}
