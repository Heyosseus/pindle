<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;

/**
 * The limit is put on the write routes as they are registered, which happens
 * once while the package boots, so proving it works means booting an application
 * that already has a limit worth reaching rather than lowering one afterwards.
 *
 * Three a minute, because the test has to exceed it and a slow suite is a suite
 * people stop running.
 */
abstract class ThrottledTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pindle.routes.throttle', '3,1');
    }
}
