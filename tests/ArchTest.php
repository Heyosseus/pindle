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
 * Models are the exception, and deliberately so: config('pindle.models') exists
 * precisely so an application can extend them.
 */
arch('source classes are final unless deliberately extended')
    ->expect('Pindle')
    ->classes()
    ->toBeFinal()
    ->ignoring('Pindle\Models');

arch('the core knows nothing about rendering')
    ->expect(['Pindle\Models', 'Pindle\Geometry', 'Pindle\Documents', 'Pindle\Policies'])
    ->not->toUse(['Illuminate\View', Illuminate\Http\Request::class]);
