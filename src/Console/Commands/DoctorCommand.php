<?php

declare(strict_types=1);

namespace Pindle\Console\Commands;

use Illuminate\Console\Command;
use Pindle\Diagnostics\Diagnostics;
use Pindle\Diagnostics\Severity;

/**
 * Check the installation and say what is wrong in the imperative.
 *
 * Worth running in a deploy script: it exits non-zero on anything that is
 * actually broken, so a stale viewer bundle or a public documents disk stops the
 * release rather than being discovered by whoever files the support ticket.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'pindle:doctor';

    protected $description = 'Check that Pindle is installed, configured and serving what it thinks it is';

    public function handle(): int
    {
        $diagnoses = Diagnostics::run();

        $this->newLine();

        $failed = 0;
        $warned = 0;

        foreach ($diagnoses as $diagnosis) {
            $this->components->twoColumnDetail(
                $diagnosis->check,
                sprintf('<fg=default>%s</> <%s>%s</>',
                    $diagnosis->detail,
                    $diagnosis->severity->style(),
                    $diagnosis->severity->label(),
                ),
            );

            if ($diagnosis->fix !== null) {
                $this->line('    <fg=gray>→ '.$diagnosis->fix.'</>');
            }

            $failed += $diagnosis->severity === Severity::Fail ? 1 : 0;
            $warned += $diagnosis->severity === Severity::Warn ? 1 : 0;
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error(sprintf('%d check(s) failed, %d warned.', $failed, $warned));

            return self::FAILURE;
        }

        if ($warned > 0) {
            $this->components->warn(sprintf('%d check(s) warned. Nothing is broken.', $warned));

            return self::SUCCESS;
        }

        $this->components->info('Everything checks out.');

        return self::SUCCESS;
    }
}
