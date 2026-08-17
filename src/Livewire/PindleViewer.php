<?php

declare(strict_types=1);

namespace Pindle\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pindle\Support\Key;

/**
 * ```blade
 * <livewire:pindle-viewer :for="$invoice" document="default" />
 * ```
 *
 * Optional, and only worth reaching for when you want the server to react to
 * what happens in the viewer without writing a listener for Pindle's Laravel
 * events. It re-dispatches two of the browser's own events as Livewire ones, so
 * a parent component can do `#[On('pindle:annotation-created')]` and be told.
 *
 * The model arrives by its morph and key rather than as a serialised model:
 * Livewire round-trips component state through the browser, and a model with
 * `#[Locked]` ids is a great deal less to trust on the way back than a whole
 * serialised Eloquent object.
 */
final class PindleViewer extends Component
{
    /** @var class-string<Model> */
    #[Locked]
    public string $annotatableType;

    #[Locked]
    public string $annotatableId = '';

    #[Locked]
    public string $document = 'default';

    #[Locked]
    public int $height = 800;

    #[Locked]
    public bool $readonly = false;

    public function mount(
        Model $for,
        string $document = 'default',
        int $height = 800,
        bool $readonly = false,
    ): void {
        $this->annotatableType = $for::class;
        $this->annotatableId = Key::of($for);
        $this->document = $document;
        $this->height = $height;
        $this->readonly = $readonly;
    }

    /**
     * Built through the factory rather than the `view()` helper.
     *
     * The helper is typed to a `view-string`, and `pindle::livewire.viewer` is
     * only resolvable once the provider has registered the namespace at boot --
     * which no static analyser can know from reading this file.
     */
    public function render(): View
    {
        return app(Factory::class)->make('pindle::livewire.viewer', ['model' => $this->model()]);
    }

    /**
     * The model, re-read on every render.
     *
     * The morph and the key are `#[Locked]`, so the browser cannot point this
     * component at somebody else's invoice between requests -- and even if it
     * could, every endpoint the viewer then calls asks the policy again.
     *
     * Null when the row has since been deleted, which the view handles: a
     * viewer whose document vanished should say so, not throw.
     */
    private function model(): ?Model
    {
        return $this->annotatableType::query()->find($this->annotatableId);
    }
}
