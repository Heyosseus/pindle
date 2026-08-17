<?php

declare(strict_types=1);

namespace Pindle\Support;

/**
 * The two tags `@pindleScripts` emits.
 *
 * Emitted once per request however many times the directive is called. A layout
 * that includes it and a Filament page that also does would otherwise load
 * PDFium twice -- four and a half megabytes of WebAssembly, and two viewers
 * racing to mount the same element.
 *
 * "Once per request" is why this is an object bound `scoped` in the container
 * rather than a class with a static flag. Under Octane, FrankenPHP or any other
 * worker runtime the process outlives the request, and a static flag set on the
 * first request stays set: every subsequent response in that worker would omit
 * the tags entirely and the viewer would simply never appear. Scoped bindings
 * are forgotten between requests, which is exactly the lifetime this needs.
 *
 * @internal
 */
final class Assets
{
    private bool $emitted = false;

    public function tags(): string
    {
        if ($this->emitted) {
            return '';
        }

        $this->emitted = true;

        return sprintf(
            '<link rel="stylesheet" href="%s">'."\n".'<script src="%s" defer></script>',
            e($this->url('pindle.css')),
            e($this->url('pindle.js')),
        );
    }

    /**
     * A published asset's URL, stamped with the bundle's version.
     *
     * The stamp is what stops a browser serving last month's viewer out of its
     * cache after an upgrade. It is the content hash rather than a release
     * number, so it changes when and only when the file does -- a redeploy that
     * did not touch the viewer does not invalidate anybody's cache.
     */
    public function url(string $file): string
    {
        return asset('vendor/pindle/'.$file).'?id='.Bundle::version();
    }

    /**
     * Forget that the tags were emitted, for a test that renders twice.
     */
    public function flush(): void
    {
        $this->emitted = false;
    }
}
