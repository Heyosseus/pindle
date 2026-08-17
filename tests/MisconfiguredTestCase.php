<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;

/**
 * An application whose Pindle configuration is wrong in the ways a hand-edited
 * config file actually goes wrong. The middleware stack is read while routes are
 * being registered, so proving it copes means booting it broken.
 */
abstract class MisconfiguredTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A string where a list belongs, and one entry that is not a class name.
        $app['config']->set('pindle.routes.middleware', 'web');
    }
}
