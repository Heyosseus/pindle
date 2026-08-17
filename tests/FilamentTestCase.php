<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ViewErrorBag;
use Override;

/**
 * Boots enough of Filament for the field and the entry to be built and rendered.
 *
 * Testbench does not run Laravel's package discovery, so Filament's own
 * providers and the packages it relies on are named here by hand, in dependency
 * order. Everything under `tests/Filament` runs against this; nothing else does,
 * which is what keeps Filament out of the rest of the suite and lets the whole
 * thing pass in an application that has never installed it.
 */
abstract class FilamentTestCase extends TestCase
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
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,

            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,

            ...parent::getPackageProviders($app),
        ];
    }
}
