<?php

declare(strict_types=1);

namespace Pindle\Support;

/**
 * The two tags `@pindleScripts` emits.
 *
 * Emitted once per request however many times the directive is called. A layout
 * that includes it and a Filament page that also does would otherwise load
 * PDFium twice -- nine megabytes of WebAssembly, and two viewers racing to mount
 * the same element.
 *
 * @internal
 */
final class Assets
{
    private static bool $emitted = false;

    public static function tags(): string
    {
        if (self::$emitted) {
            return '';
        }

        self::$emitted = true;

        $script = e(asset('vendor/pindle/pindle.js'));
        $style = e(asset('vendor/pindle/pindle.css'));

        return sprintf(
            '<link rel="stylesheet" href="%s">'."\n".'<script src="%s" defer></script>',
            $style,
            $script,
        );
    }

    /**
     * Forget that the tags were emitted. The flag is static, so it outlives the
     * request in a long-running worker and in the test suite.
     */
    public static function flush(): void
    {
        self::$emitted = false;
    }
}
