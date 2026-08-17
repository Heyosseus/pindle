<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('registers nothing at all when it is switched off', function (): void {
    expect(Artisan::all())->not->toHaveKey('pindle:prune');
});
