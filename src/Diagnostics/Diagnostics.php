<?php

declare(strict_types=1);

namespace Pindle\Diagnostics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Pindle\Concerns\HasAnnotations;
use Pindle\Pindle;
use Pindle\Support\Bundle;
use Throwable;

/**
 * Everything that can be wrong with an installation and answered without
 * guessing.
 *
 * The point of this is the two failures nobody notices. Documents served off a
 * public disk work perfectly -- right up to the moment somebody discovers the
 * URLs were never guarded at all. Published assets left behind by an upgrade
 * work perfectly for everyone whose browser cached them and for nobody else.
 * Neither throws, neither logs, and both are one command away from being
 * obvious.
 *
 * Separate from the command so it can be asserted on directly, and so an
 * application can put it in a health endpoint of its own.
 */
final class Diagnostics
{
    /**
     * @return list<Diagnosis>
     */
    public static function run(): array
    {
        $config = app(Repository::class);

        return [
            self::enabled($config),
            self::migrations(),
            self::disk($config),
            self::assets(),
            self::middleware($config),
            self::throttle($config),
            self::signedUrls($config),
            self::policies(),
        ];
    }

    /** Whether anything is registered at all. */
    private static function enabled(Repository $config): Diagnosis
    {
        if ((bool) $config->get('pindle.enabled', true)) {
            return Diagnosis::pass('Package', 'Enabled.');
        }

        return Diagnosis::warn(
            'Package',
            'Disabled, so no routes, directive or Filament field are registered.',
            'Set PINDLE_ENABLED=true if that was not deliberate.',
        );
    }

    private static function migrations(): Diagnosis
    {
        $annotations = (new (Pindle::annotationModel()))->getTable();
        $comments = (new (Pindle::commentModel()))->getTable();

        try {
            $missing = array_values(array_filter(
                [$annotations, $comments],
                static fn (string $table): bool => ! Schema::hasTable($table),
            ));
        } catch (Throwable $e) {
            return Diagnosis::fail('Migrations', 'Could not reach the database: '.$e->getMessage());
        }

        if ($missing === []) {
            return Diagnosis::pass('Migrations', 'Both tables are present.');
        }

        return Diagnosis::fail(
            'Migrations',
            'Missing '.implode(' and ', $missing).'.',
            'php artisan vendor:publish --tag=pindle-migrations && php artisan migrate',
        );
    }

    /**
     * Whether the documents disk is one the world can read.
     *
     * Pindle streams bytes through a signed route that re-authorises on every
     * request, so nothing it does hands out a disk URL. That protection is worth
     * nothing if the disk is also reachable directly, and a public disk is the
     * single mistake that quietly undoes every policy in the application.
     */
    private static function disk(Repository $config): Diagnosis
    {
        $name = $config->get('pindle.documents.disk');
        $name = is_string($name) && $name !== '' ? $name : 'local';

        $disk = $config->get('filesystems.disks.'.$name);

        if (! is_array($disk)) {
            try {
                Storage::disk($name);
            } catch (Throwable) {
                return Diagnosis::fail(
                    'Documents disk',
                    sprintf('Disk "%s" is not defined.', $name),
                    'Add it to config/filesystems.php, or point PINDLE_DISK at one that exists.',
                );
            }

            // Built at runtime rather than declared -- `Storage::build()`,
            // `Storage::extend()`, or a fake in a test suite. It works; there is
            // simply no configuration to read, so this stops short of claiming
            // it checked something it could not see.
            return Diagnosis::warn(
                'Documents disk',
                sprintf('"%s" is registered at runtime, so its visibility could not be checked.', $name),
                'Make sure it is not readable without going through a policy.',
            );
        }

        $reasons = [];

        if (($disk['visibility'] ?? null) === 'public') {
            $reasons[] = 'its default visibility is public';
        }

        $root = $disk['root'] ?? null;

        if (is_string($root) && self::isUnderPublicPath($root)) {
            $reasons[] = 'its root is inside the public directory';
        }

        if ($reasons === []) {
            return Diagnosis::pass('Documents disk', sprintf('"%s" is not publicly readable.', $name));
        }

        return Diagnosis::fail(
            'Documents disk',
            sprintf('"%s" is readable without going through a policy: %s.', $name, implode(' and ', $reasons)),
            'Point PINDLE_DISK at a private disk. Pindle streams documents through a signed, expiring route instead.',
        );
    }

    private static function assets(): Diagnosis
    {
        $missing = Bundle::missing();

        if ($missing === Bundle::FILES) {
            return Diagnosis::fail(
                'Viewer assets',
                'Nothing is published, so the viewer cannot load.',
                'php artisan vendor:publish --tag=pindle-assets',
            );
        }

        if ($missing !== []) {
            return Diagnosis::fail(
                'Viewer assets',
                'Published but incomplete: missing '.implode(', ', $missing).'.',
                'php artisan vendor:publish --tag=pindle-assets --force',
            );
        }

        if (! Bundle::isPublishedCurrent()) {
            return Diagnosis::fail(
                'Viewer assets',
                sprintf(
                    'Stale: serving %s, this package ships %s.',
                    Bundle::publishedVersion() ?? 'unknown',
                    Bundle::version(),
                ),
                'php artisan vendor:publish --tag=pindle-assets --force',
            );
        }

        return Diagnosis::pass('Viewer assets', 'Published and current ('.Bundle::version().').');
    }

    /**
     * Whether the endpoints sit behind anything that establishes who is asking.
     *
     * Every endpoint authorises through the application's policy, and a policy
     * asked about a guest is a policy asked the wrong question. Pindle does not
     * add an authenticator of its own, so this is the one place that says out
     * loud when there is none.
     */
    private static function middleware(Repository $config): Diagnosis
    {
        $stack = $config->get('pindle.routes.middleware', []);
        $stack = is_array($stack) ? array_filter($stack, is_string(...)) : [];

        foreach ($stack as $middleware) {
            if (str_starts_with($middleware, 'auth')) {
                return Diagnosis::pass('Route middleware', 'Authenticated: '.implode(', ', $stack).'.');
            }
        }

        return Diagnosis::warn(
            'Route middleware',
            $stack === []
                ? 'No middleware at all, so every request arrives as a guest.'
                : 'Nothing authenticating in: '.implode(', ', $stack).'.',
            'Add "auth" to pindle.routes.middleware unless your policies really do answer for guests.',
        );
    }

    /**
     * Whether the write endpoints are rate limited.
     *
     * A warning rather than a failure: an application that limits for itself,
     * at the edge or in its own middleware stack, is better off than one that
     * limits twice. But an application that turned the shipped limit off and
     * forgot is worth telling.
     */
    private static function throttle(Repository $config): Diagnosis
    {
        $limit = $config->get('pindle.routes.throttle');

        if (is_string($limit) && trim($limit) !== '') {
            return Diagnosis::pass('Write rate limit', 'throttle:'.trim($limit).' on the write endpoints.');
        }

        return Diagnosis::warn(
            'Write rate limit',
            'None, so a single client can write as fast as it can ask.',
            'Set pindle.routes.throttle to a rate ("60,1") or a named limiter, unless you limit these routes yourself.',
        );
    }

    private static function signedUrls(Repository $config): Diagnosis
    {
        $ttl = $config->get('pindle.documents.url_ttl');
        $ttl = is_numeric($ttl) ? (int) $ttl : 0;

        if ($ttl < 1) {
            return Diagnosis::fail(
                'Document URLs',
                'The signed URL lifetime is not a positive number of seconds, so every link expires on arrival.',
                'Set pindle.documents.url_ttl to something like 300.',
            );
        }

        // Long enough to be worth stealing. The URL only has to outlive the
        // browser's first fetch; PDFium re-uses it for the range requests that
        // follow, which all happen within seconds.
        if ($ttl > 3600) {
            return Diagnosis::warn(
                'Document URLs',
                sprintf('Signed URLs live for %d seconds.', $ttl),
                'A few minutes is plenty -- the link only has to outlive the first fetch.',
            );
        }

        return Diagnosis::pass('Document URLs', sprintf('Signed and expiring after %d seconds.', $ttl));
    }

    /**
     * Whether the models people have actually annotated can answer a policy
     * question.
     *
     * Read from the annotations table rather than from a list of classes,
     * because there is no register of which models use the trait and the ones
     * with marks on them are the ones that matter. A model with no policy denies
     * everything, which presents as "the viewer loads and nothing saves" -- a
     * bug report nobody connects to authorisation.
     */
    private static function policies(): Diagnosis
    {
        try {
            $types = Pindle::query()
                ->distinct()
                ->pluck('annotatable_type')
                ->filter(static fn (mixed $type): bool => is_string($type))
                ->all();
        } catch (Throwable) {
            return Diagnosis::warn('Policies', 'Could not read the annotations table to check.');
        }

        $unguarded = [];
        $untraited = [];

        foreach ($types as $type) {
            $class = Model::getActualClassNameForMorph($type);

            // A morph type naming no class: a model that has since been renamed
            // or removed, leaving its marks behind. Worth pruning, but not
            // worth reporting as an authorisation problem -- there is nothing
            // left to write a policy for.
            if (! class_exists($class)) {
                continue;
            }

            if (Gate::getPolicyFor($class) === null) {
                $unguarded[] = $class;
            }

            if (! in_array(HasAnnotations::class, class_uses_recursive($class), true)) {
                $untraited[] = $class;
            }
        }

        if ($unguarded === [] && $untraited === []) {
            return Diagnosis::pass(
                'Policies',
                $types === [] ? 'Nothing annotated yet, nothing to check.' : 'Every annotated model has one.',
            );
        }

        if ($unguarded !== []) {
            return Diagnosis::fail(
                'Policies',
                'No policy registered for '.implode(', ', $unguarded).', so every request about them is denied.',
                'php artisan make:policy '.class_basename($unguarded[0]).' --model='.class_basename($unguarded[0]),
            );
        }

        return Diagnosis::warn(
            'Policies',
            'Annotated but not using the trait: '.implode(', ', $untraited).'.',
            'Add Pindle\Concerns\HasAnnotations to them.',
        );
    }

    private static function isUnderPublicPath(string $root): bool
    {
        $public = realpath(public_path());
        $resolved = realpath($root);

        return $public !== false && $resolved !== false && str_starts_with($resolved, $public);
    }

    /** Whether anything found is bad enough to fail a deploy over. */
    public static function isHealthy(): bool
    {
        foreach (self::run() as $diagnosis) {
            if ($diagnosis->severity === Severity::Fail) {
                return false;
            }
        }

        return true;
    }
}
