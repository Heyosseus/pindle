<?php

declare(strict_types=1);

namespace Pindle\Filament;

use Filament\Forms\Components\Field;

/**
 * ```php
 * PindleViewer::make('pdf_path')->documentKey('delivery_note')->viewerHeight(640)
 * ```
 *
 * A form field only in the sense that it lives in a form. It stores nothing --
 * annotations go to Pindle's own tables, not to the field's state -- so it is
 * `dehydrated(false)` and never appears in what the form saves.
 *
 * The field name is the attribute holding the path, which is what makes the
 * call read naturally beside the rest of a form. What Pindle actually resolves
 * is the *document key*, and that defaults to the field name so the common case
 * -- one PDF, on `pdf_path`, mapped as `default` -- needs nothing said.
 */
final class PindleViewer extends Field
{
    use Concerns\ConfiguresViewer;

    protected string $view = 'pindle::filament.viewer';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here belongs in the form's payload: the field is a window onto
        // annotations that live in their own tables, and a form that tried to
        // save it would write the PDF path back over itself.
        $this->dehydrated(false);

        $this->columnSpanFull();
    }
}
