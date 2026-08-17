<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Filament\Infolists\Components\Entry;

/**
 * ```php
 * PindleEntry::make('pdf_path')->readonly()
 * ```
 *
 * The same viewer on a record page rather than in a form. It is the natural
 * place for `readonly()`: an infolist is where a document is read, and a good
 * many applications want everyone to see the marks and only some people to make
 * them.
 */
final class PindleEntry extends Entry
{
    use Concerns\ConfiguresViewer;

    protected string $view = 'pindle::filament.viewer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->columnSpanFull();
    }
}
