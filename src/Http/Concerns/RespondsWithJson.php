<?php

declare(strict_types=1);

namespace Pindle\Http\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * JSON that gives coordinates back the way they were stored.
 *
 * Without `JSON_PRESERVE_ZERO_FRACTION`, an anchor at x1 = 72.0 goes out as
 * `72` and comes back as an integer. Nothing breaks -- JavaScript has one number
 * type and the value is identical -- but the API stops being self-describing:
 * half the ordinates in a payload look like integers and half like floats,
 * depending only on whether the highlight happened to land on a whole point.
 * Anyone reading the wire format, or writing a client in a typed language, then
 * has to discover that ordinates are floats from the documentation rather than
 * from the data.
 */
trait RespondsWithJson
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        // The options have to be in place before the data is, not after. Setting
        // them afterwards re-encodes the JSON string it already produced, and by
        // then 72.0 has already become 72 and there is no fraction left to
        // preserve.
        $response = new JsonResponse;

        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data)->setStatusCode($status);
    }
}
