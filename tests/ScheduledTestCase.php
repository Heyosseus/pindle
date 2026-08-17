<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;

/**
 * Pruning is off by default -- forgetting rows is not something a package should
 * start doing because it was installed -- so the tests that prove the schedule
 * boot an application that asked for it.
 */
abstract class ScheduledTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pindle.pruning.enabled', true);
    }
}
