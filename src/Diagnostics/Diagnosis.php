<?php

declare(strict_types=1);

namespace Pindle\Diagnostics;

/**
 * One thing that was checked, and what was found.
 *
 * A diagnosis that says what is wrong without saying what to type is half a
 * diagnosis, so `fix` is a command or an edit rather than advice.
 */
final readonly class Diagnosis
{
    public function __construct(
        public string $check,
        public Severity $severity,
        public string $detail,
        public ?string $fix = null,
    ) {}

    public static function pass(string $check, string $detail): self
    {
        return new self($check, Severity::Pass, $detail);
    }

    public static function warn(string $check, string $detail, ?string $fix = null): self
    {
        return new self($check, Severity::Warn, $detail, $fix);
    }

    public static function fail(string $check, string $detail, ?string $fix = null): self
    {
        return new self($check, Severity::Fail, $detail, $fix);
    }
}
