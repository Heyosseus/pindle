<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

use RuntimeException;

/**
 * A stream that cannot be sought, which is what an S3 disk hands back.
 *
 * Deliberately a userland wrapper rather than a socket pair. `stream_socket_pair`
 * takes a different address family on Windows (`STREAM_PF_INET`) from the one it
 * takes on Linux (`STREAM_PF_UNIX`), so a test written against either passes on
 * one developer's machine and fails in CI on the other. A wrapper behaves the
 * same everywhere.
 *
 * The absence of a `stream_seek` method is the whole point: PHP marks a userland
 * stream non-seekable exactly when its wrapper does not implement one, which is
 * the condition {@see \Pindle\Documents\DocumentStream::skipTo()} branches on.
 */
final class UnseekableStream
{
    public const string PROTOCOL = 'pindle-unseekable';

    private static string $contents = '';

    /** Required by PHP on every stream wrapper, whether or not it is used. */
    public mixed $context = null;

    private int $position = 0;

    /**
     * Register the wrapper, replacing any previous registration.
     */
    public static function serve(string $contents): void
    {
        self::$contents = $contents;

        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }

        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    /**
     * @return resource
     */
    public static function open(string $contents)
    {
        self::serve($contents);

        $stream = fopen(self::PROTOCOL.'://document.pdf', 'r');

        if (! is_resource($stream)) {
            throw new RuntimeException('The unseekable stream would not open.');
        }

        return $stream;
    }

    public function stream_open(): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$contents, $this->position, $count);

        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * Accept the call and refuse to move, which is what a streaming adapter
     * does -- and is worse than refusing outright, because the caller is told
     * nothing. Catching it is the reason `skipTo` checks `ftell` afterwards
     * instead of trusting the seek.
     */
    public function stream_seek(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$contents)];
    }
}
