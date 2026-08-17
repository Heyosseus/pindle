<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Pindle\Exceptions\DocumentUnreadable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A PDF handed to the browser a slice at a time.
 *
 * Range support is not a nicety here. PDFium fetches the trailer first, then the
 * cross-reference table, then only the objects the page on screen actually
 * needs; without ranges every one of those is a fresh download of the whole
 * file, and a hundred-page contract takes as long to show its first page as to
 * show all of them. `Accept-Ranges` is what tells it that it may.
 *
 * The disposition is always inline. A viewer that triggers a download instead of
 * rendering is not a viewer.
 */
final readonly class DocumentStream
{
    private const int CHUNK = 262_144;

    public function __construct(private PindleDocument $document) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function response(?string $range, array $headers = []): Response
    {
        $size = $this->document->size();

        $headers = array_merge($headers, [
            'Content-Type' => $this->document->mimeType,
            'Content-Disposition' => sprintf('inline; filename="%s"', $this->safeFilename()),
            'Accept-Ranges' => 'bytes',
            // The bytes are behind a policy and a short-lived signature; a shared
            // cache holding on to them would outlive both.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $span = $range === null ? null : Range::parse($range, $size);

        if (! $span instanceof Range) {
            return $this->send(0, $size - 1, $size, Response::HTTP_OK, $headers);
        }

        if (! $span->isSatisfiable()) {
            // 416 must say what the acceptable range would have been, or the
            // client has no way to correct itself.
            return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                'Content-Range' => sprintf('bytes */%d', $size),
                'Accept-Ranges' => 'bytes',
            ]);
        }

        $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $span->start, $span->end, $size);

        return $this->send($span->start, $span->end, $size, Response::HTTP_PARTIAL_CONTENT, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(int $start, int $end, int $size, int $status, array $headers): StreamedResponse
    {
        $length = $size === 0 ? 0 : $end - $start + 1;

        $headers['Content-Length'] = (string) $length;

        $document = $this->document;

        return new StreamedResponse(static function () use ($document, $start, $length): void {
            $stream = $document->filesystem()->readStream($document->path);

            if (! is_resource($stream)) {
                throw DocumentUnreadable::at($document->disk, $document->path);
            }

            self::skipTo($stream, $start);

            $remaining = $length;

            // "Nothing came back" is the only end condition, rather than feof as
            // well. A document that is shorter than the length already sent in
            // the header -- replaced between the size being taken and the body
            // being sent -- stops here, and so does an adapter whose stream ends
            // without ever setting feof.
            while ($remaining > 0) {
                $chunk = fread($stream, min(self::CHUNK, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;

                $remaining -= strlen($chunk);

                flush();
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Advance a stream to the first byte of the range.
     *
     * Not every adapter's stream is seekable -- S3's is not, out of the box --
     * so where the offset cannot be sought it is read past instead. Public
     * because a branch that only a non-seekable adapter reaches is a branch no
     * test could otherwise get to, and an untested seek is how a package starts
     * serving the wrong slice of a document to somebody.
     *
     * @param  resource  $stream
     *
     * @internal
     */
    public static function skipTo(mixed $stream, int $start): void
    {
        if ($start <= 0 || ! is_resource($stream)) {
            return;
        }

        // Asked before attempted, then checked afterwards.
        //
        // Asked, because seeking a stream that announces itself unseekable
        // raises a warning, and a warning is an exception in most Laravel
        // applications -- which would turn "this disk streams differently" into
        // a failed download.
        //
        // Checked, because the announcement is not reliable. Userland stream
        // wrappers are reported seekable whether or not they implement seeking
        // at all, and an adapter that accepts the seek and ignores it would
        // otherwise have us serve the wrong slice of somebody's contract with
        // complete confidence. `ftell` is the only answer worth trusting.
        if (stream_get_meta_data($stream)['seekable']) {
            fseek($stream, $start);

            if (ftell($stream) === $start) {
                return;
            }
        }

        $remaining = $start - (int) ftell($stream);

        while ($remaining > 0) {
            $chunk = fread($stream, min(self::CHUNK, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * The filename with anything that could break out of the header quoted away.
     */
    private function safeFilename(): string
    {
        return preg_replace('/[^\w.\- ]/u', '_', $this->document->filename()) ?? 'document.pdf';
    }
}
