<?php

declare(strict_types=1);

namespace Pindle\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What the field and the entry share, which is everything except which base
 * class they extend.
 *
 * Both are thin wrappers over the same Blade component -- deliberately, because
 * a second implementation of the viewer for Filament would be a second place
 * for the coordinate handling to go wrong.
 */
trait ConfiguresViewer
{
    protected string|Closure|null $pindleDocumentKey = null;

    protected int|Closure $pindleHeight = 800;

    protected bool|Closure $pindleReadonly = false;

    /**
     * Which of the model's documents to show. Defaults to the component's own
     * name, so `PindleViewer::make('pdf_path')` needs nothing further when the
     * model maps that attribute under a key of the same name.
     */
    public function documentKey(string|Closure|null $key): static
    {
        $this->pindleDocumentKey = $key;

        return $this;
    }

    public function viewerHeight(int|Closure $height): static
    {
        $this->pindleHeight = $height;

        return $this;
    }

    public function readonly(bool|Closure $readonly = true): static
    {
        $this->pindleReadonly = $readonly;

        return $this;
    }

    public function getDocumentKey(): string
    {
        $key = $this->evaluate($this->pindleDocumentKey);

        if (is_string($key) && $key !== '') {
            return $key;
        }

        return $this->getName();
    }

    public function getViewerHeight(): int
    {
        $height = $this->evaluate($this->pindleHeight);

        return is_numeric($height) && (int) $height > 0 ? (int) $height : 800;
    }

    public function isViewerReadonly(): bool
    {
        return (bool) $this->evaluate($this->pindleReadonly);
    }

    /**
     * The model the viewer is about.
     *
     * Null on a create form, where there is no record yet and so no document to
     * annotate -- the Blade component says so rather than erroring.
     */
    public function getViewerRecord(): ?Model
    {
        $record = $this->getRecord();

        return $record instanceof Model ? $record : null;
    }
}
