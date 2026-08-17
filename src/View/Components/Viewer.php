<?php

declare(strict_types=1);

namespace Pindle\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Component;
use Pindle\Contracts\DocumentResolver;
use Pindle\Documents\DocumentSignature;
use Pindle\Documents\PindleDocument;
use Pindle\Support\Key;

/**
 * ```blade
 * <x-pindle::viewer :for="$invoice" document="default" :height="800" />
 * ```
 *
 * Everything the browser needs arrives as one JSON attribute on the root
 * element, and the bundle mounts anything carrying it. That indirection is what
 * lets the same component work unchanged in a Blade page, inside a Livewire
 * component, and in a Filament panel: none of them has to call an initialiser,
 * so none of them can forget to.
 */
final class Viewer extends Component
{
    private ?PindleDocument $resolved = null;

    public function __construct(
        public Model $for,
        public string $document = 'default',
        public int $height = 800,
        public bool $readonly = false,
    ) {}

    /**
     * The view's name rather than the view itself.
     *
     * Laravel resolves a string return against the view factory, and naming it
     * keeps the package's own namespace out of a `view()` call that static
     * analysis has no way to resolve -- `pindle::` is registered at boot, and no
     * analyser can know that from the source.
     */
    public function render(): string
    {
        return 'pindle::components.viewer';
    }

    /**
     * The configuration the bundle reads off the root element.
     *
     * The document URL is minted here, per render, for the person looking at
     * the page -- which is why it expires harmlessly and why it is useless to
     * anyone else.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'base' => $this->prefix(),
            'csrfToken' => $this->csrfToken(),
            'annotatableType' => $this->for->getMorphClass(),
            'annotatableId' => Key::of($this->for),
            'documentKey' => $this->document,
            'documentUrl' => $this->documentUrl(),
            'wasmUrl' => $this->asset('pdfium.wasm'),
            'readonly' => $this->readonly,
            'maxCommentLength' => $this->maxCommentLength(),
        ];
    }

    /**
     * Whether there is anything to show at all.
     *
     * A model whose document has not been uploaded yet is an ordinary state,
     * and the component says so rather than booting a viewer over nothing.
     */
    public function hasDocument(): bool
    {
        $document = $this->pindleDocument();

        return $document instanceof PindleDocument && $document->exists();
    }

    private function documentUrl(): string
    {
        return $this->hasDocument()
            ? DocumentSignature::url($this->for, $this->document, Auth::user())
            : '';
    }

    /**
     * Resolved once per render. The Blade view asks whether there is a document
     * and then asks for its URL, and a resolution is a read of the disk.
     */
    private function pindleDocument(): ?PindleDocument
    {
        return $this->resolved ??= app(DocumentResolver::class)->resolve($this->for, $this->document);
    }

    /**
     * The token the API client sends back on every write.
     *
     * Empty where there is no session -- a component rendered from a console
     * command or a mail template has none, and the viewer is not usable there
     * anyway. Throwing would turn "rendered in an odd place" into a 500.
     */
    private function csrfToken(): string
    {
        $request = request();

        return $request->hasSession() ? $request->session()->token() : '';
    }

    private function prefix(): string
    {
        $prefix = config('pindle.routes.prefix', 'pindle');

        return URL::to(is_string($prefix) ? $prefix : 'pindle');
    }

    private function asset(string $file): string
    {
        return asset('vendor/pindle/'.$file);
    }

    private function maxCommentLength(): int
    {
        $max = config('pindle.comments.max_length');

        return is_numeric($max) && (int) $max > 0 ? (int) $max : 2_000;
    }

    /**
     * The settings as the attribute value, encoded once.
     */
    public function encoded(): string
    {
        return json_encode($this->settings(), JSON_THROW_ON_ERROR);
    }
}
