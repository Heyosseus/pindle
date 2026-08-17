{{--
    The viewer's root.

    `wire:ignore` is not decoration. Livewire diffs the DOM it owns on every
    round trip, and the canvas underneath this element is painted by PDFium
    rather than by Blade -- so a diff that "corrects" it destroys the rendered
    page and every mark drawn over it. Ignoring the subtree is what makes the
    same component safe in a Livewire form and in a plain Blade page.

    The bundle finds this element by its data attribute rather than being
    called, so nothing here has to run JavaScript of its own.
--}}
@if ($hasDocument())
    <div
        {{ $attributes->merge(['class' => 'pindle']) }}
        wire:ignore
        data-pindle="{{ $encoded() }}"
        style="height: {{ $height }}px"
    ></div>
@else
    <div {{ $attributes->merge(['class' => 'pindle pindle--empty']) }}>
        <p>There is no document to annotate yet.</p>
    </div>
@endif
