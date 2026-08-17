<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Pindle\Exceptions\DocumentUnreadable;

/**
 * One PDF belonging to one model: where it lives, what it is called, and what it
 * hashes to.
 *
 * The hash is the interesting part and the reason this is an object rather than
 * a path string. It is computed lazily, because resolving a document happens on
 * every request that renders a viewer and hashing happens only when an
 * annotation is written or checked. And it is computed by streaming, because a
 * scanned contract is tens of megabytes and reading one into a string to hash it
 * is how a package earns a reputation for exhausting memory.
 */
final class PindleDocument
{
    private ?string $hash = null;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $key = 'default',
        public readonly ?string $filename = null,
        public readonly string $mimeType = 'application/pdf',
    ) {}

    /**
     * The name to show, falling back to the last segment of the path.
     */
    public function filename(): string
    {
        return $this->filename ?? basename($this->path);
    }

    public function exists(): bool
    {
        return $this->filesystem()->exists($this->path);
    }

    /**
     * The size in bytes, which range requests need in order to answer at all.
     *
     * Zero for a file that is not there, rather than the adapter's exception:
     * a missing document is an empty viewer and a 404, and both of those are
     * decisions for the layer above this one to make.
     */
    public function size(): int
    {
        if (! $this->exists()) {
            return 0;
        }

        return $this->filesystem()->size($this->path);
    }

    /**
     * The sha256 of the bytes, memoised for the life of this object.
     *
     * Memoised and not cached: a document that is replaced between two requests
     * must hash differently in the second, since that difference is the only
     * thing telling an annotation that the page underneath it moved. A cache
     * with any TTL at all would hide exactly the event this exists to catch.
     */
    public function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }

        $stream = $this->filesystem()->readStream($this->path);

        if (! is_resource($stream)) {
            throw DocumentUnreadable::at($this->disk, $this->path);
        }

        $context = hash_init('sha256');

        hash_update_stream($context, $stream);

        fclose($stream);

        return $this->hash = hash_final($context);
    }

    /**
     * Whether the document is still the one an annotation was drawn on.
     */
    public function matches(string $hash): bool
    {
        return hash_equals($this->hash(), $hash);
    }

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
