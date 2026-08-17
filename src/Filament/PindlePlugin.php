<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * ```php
 * $panel->plugin(PindlePlugin::make()->viewerHeight(640))
 * ```
 *
 * Panel-level defaults, and nothing else. Pindle's viewer is a field and an
 * entry rather than a page or a resource, so there is no navigation to register
 * and no route to add -- the plugin exists so that "every viewer in this panel
 * is 640 high and read-only for everyone but reviewers" is said once.
 */
final class PindlePlugin implements Plugin
{
    private int|Closure $height = 800;

    private bool|Closure $readonly = false;

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'pindle';
    }

    public function viewerHeight(int|Closure $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function readonly(bool|Closure $readonly = true): self
    {
        $this->readonly = $readonly;

        return $this;
    }

    /**
     * Apply the panel's defaults to every viewer built afterwards.
     *
     * `configureUsing` rather than a constructor argument, so a field that says
     * something for itself still wins -- the panel sets the default, the call
     * site overrides it.
     */
    public function register(Panel $panel): void
    {
        $height = $this->height;
        $readonly = $this->readonly;

        PindleViewer::configureUsing(static function (PindleViewer $viewer) use ($height, $readonly): void {
            $viewer->viewerHeight($height)->readonly($readonly);
        });

        PindleEntry::configureUsing(static function (PindleEntry $entry) use ($height, $readonly): void {
            $entry->viewerHeight($height)->readonly($readonly);
        });
    }

    public function boot(Panel $panel): void
    {
        // Nothing to do at boot. Pindle adds no navigation, no page and no route
        // to a panel; its defaults are all applied at registration.
    }
}
