<?php

declare(strict_types=1);

namespace Pindle\Support;

/**
 * The compiled viewer: the copy inside the package, and the copy the application
 * published into `public/vendor/pindle`.
 *
 * Those two drift, and the drift is silent. `composer update` replaces the first
 * and never touches the second, so an application that upgraded a month ago is
 * still serving the viewer it published in March while the PHP half of the
 * package moved on. Nothing fails loudly: the browser loads a bundle that talks
 * a slightly older API to endpoints that have changed, and somebody eventually
 * reports that comments stopped saving.
 *
 * A version is a hash of what is actually in the file, so the two copies can be
 * compared and `pindle:doctor` can say plainly which one you are serving.
 */
final class Bundle
{
    /**
     * The published name, and whether serving a stale one breaks anything.
     *
     * `pdfium.wasm` is EmbedPDF's renderer and changes only when that dependency
     * does; the two small files are Pindle's own and are what actually drift.
     *
     * @var list<string>
     */
    public const FILES = ['pindle.js', 'pindle.css', 'pdfium.wasm'];

    /**
     * The files a version is computed over.
     *
     * The wasm is excluded deliberately. It is four and a half megabytes, it is
     * hashed on every cold worker if included, and it never changes without
     * `pindle.js` changing too -- so it costs a great deal to tell you nothing
     * the other two have not already said.
     *
     * @var list<string>
     */
    private const VERSIONED = ['pindle.js', 'pindle.css'];

    private static ?string $version = null;

    /** Where the package keeps its own compiled copy. */
    public static function packagedPath(): string
    {
        return dirname(__DIR__, 2).'/resources/dist';
    }

    /** Where `vendor:publish` put the application's copy. */
    public static function publishedPath(): string
    {
        return public_path('vendor/pindle');
    }

    /**
     * The version of the bundle shipped in this installed package.
     *
     * Memoised for the life of the process rather than the request: the files
     * inside `vendor/` do not change while PHP is running, and hashing them on
     * every render would be work with a known answer.
     */
    public static function version(): string
    {
        return self::$version ??= self::hash(self::packagedPath()) ?? 'dev';
    }

    /**
     * The version of the copy being served, or null when nothing is published.
     *
     * Not memoised, because this one does change underneath a running process --
     * that is the whole point of `vendor:publish --force`, and a doctor run that
     * reported the state from before the fix would be worse than no doctor.
     */
    public static function publishedVersion(): ?string
    {
        return self::hash(self::publishedPath());
    }

    /** Whether what is being served is what this package build produces. */
    public static function isPublishedCurrent(): bool
    {
        return self::publishedVersion() === self::version();
    }

    /**
     * The published files that are missing, if any.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $missing = [];

        foreach (self::FILES as $file) {
            if (! is_file(self::publishedPath().'/'.$file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * A short hash over a directory's versioned files, or null if any is absent.
     *
     * Absent means "not published" rather than "published as nothing", and the
     * two want different advice, so this returns null rather than the hash of
     * an empty string.
     */
    private static function hash(string $directory): ?string
    {
        $context = hash_init('sha256');

        foreach (self::VERSIONED as $file) {
            $path = $directory.'/'.$file;

            if (! is_file($path)) {
                return null;
            }

            hash_update_file($context, $path);
        }

        return substr(hash_final($context), 0, 12);
    }
}
