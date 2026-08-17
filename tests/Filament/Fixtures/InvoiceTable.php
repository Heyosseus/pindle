<?php

declare(strict_types=1);

namespace Pindle\Tests\Filament\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Pindle\Filament\PindleReviewColumn;
use Pindle\Tests\Fixtures\Invoice;

/**
 * An index screen with the review column on it, which is the arrangement the
 * column exists for and the only one that can show what it costs.
 *
 * Deliberately a real Filament table rather than a column held in a variable: a
 * column asked for its value outside a table has nobody to batch with, so
 * testing it that way would prove the batching works by never running it.
 */
class InvoiceTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Filament paginates by default; a table told not to hands back a plain collection. */
    public bool $paginate = true;

    /**
     * Panels supply one of these; a bare Livewire component does not, and there
     * is nothing here to translate.
     */
    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Invoice::query())
            ->paginated($this->paginate)
            ->columns([PindleReviewColumn::make('review')]);
    }

    /**
     * Nothing is asserted about the markup, only about the queries behind it,
     * so the component renders the smallest thing Livewire will accept.
     */
    public function render(): string
    {
        return '<div></div>';
    }
}
