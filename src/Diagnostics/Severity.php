<?php

declare(strict_types=1);

namespace Pindle\Diagnostics;

/**
 * How much a diagnosis matters.
 *
 * Three levels rather than two, because "your documents are on a public disk"
 * and "you have not published the viewer yet" are both wrong and only one of
 * them is an emergency. A check that shouted equally about each would train
 * everybody to ignore both.
 */
enum Severity: string
{
    /** Nothing to do. */
    case Pass = 'pass';

    /** Works today, will bite eventually, or is not the way you meant it. */
    case Warn = 'warn';

    /** Broken, or handing out documents your policies were supposed to guard. */
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'OK',
            self::Warn => 'WARN',
            self::Fail => 'FAIL',
        };
    }

    /** The console style to render it in. */
    public function style(): string
    {
        return match ($this) {
            self::Pass => 'info',
            self::Warn => 'comment',
            self::Fail => 'error',
        };
    }
}
