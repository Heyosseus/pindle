<?php

declare(strict_types=1);

use Pindle\Support\Throttle;

/*
 * What the configured limit turns into. That it is actually enforced -- and that
 * reading is left out of it -- is proven in tests/Throttled, against an
 * application booted with a limit low enough to reach.
 */

it('adds nothing when the application would rather limit for itself', function (): void {
    config(['pindle.routes.throttle' => null]);

    expect(Throttle::middleware())->toBe([]);

    config()->set('pindle.routes.throttle', '   ');

    expect(Throttle::middleware())->toBe([]);
});

it('takes a rate or the name of a limiter', function (): void {
    config()->set('pindle.routes.throttle', ' 60,1 ');

    expect(Throttle::middleware())->toBe(['throttle:60,1']);

    config()->set('pindle.routes.throttle', 'reviews');

    expect(Throttle::middleware())->toBe(['throttle:reviews']);
});

it('ships a limit rather than leaving one to be remembered', function (): void {
    $shipped = require dirname(__DIR__, 3).'/config/pindle.php';

    expect($shipped['routes']['throttle'])->toBe('60,1');
});
