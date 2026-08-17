<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();

arch('the package ships no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Pindle')
    ->toUseStrictTypes();

/*
 * The load-bearing claim of the package: Filament and Livewire are `suggest`,
 * never `require`. Nothing outside the adapters may mention either, or a
 * plain-Blade application would find it had acquired a dependency it never
 * asked for.
 */
arch('neither filament nor livewire leaks out of the adapters')
    ->expect(['Pindle\Models', 'Pindle\Documents', 'Pindle\Http', 'Pindle\Geometry', 'Pindle\Policies', 'Pindle\View'])
    ->not->toUse(['Filament', 'Livewire']);

/*
 * Models are the exception, and deliberately so: config('pindle.models') exists
 * precisely so an application can extend them.
 *
 * The adapters are excluded for a different reason: their parent classes come
 * from Filament and Livewire, which are `suggest` only. Reflecting on a class
 * whose parent is not installed is a fatal error, and the suite has to pass in
 * an application that has installed neither. They are final all the same.
 */
arch('source classes are final unless deliberately extended')
    ->expect('Pindle')
    ->classes()
    ->toBeFinal()
    ->ignoring(['Pindle\Models', 'Pindle\Filament', 'Pindle\Livewire']);

arch('the core knows nothing about rendering')
    ->expect(['Pindle\Models', 'Pindle\Geometry', 'Pindle\Documents', 'Pindle\Policies'])
    ->not->toUse(['Illuminate\View', Illuminate\Http\Request::class]);
