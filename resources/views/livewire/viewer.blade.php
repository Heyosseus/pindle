{{--
    The Livewire wrapper.

    The viewer's own root carries wire:ignore, so Livewire never touches the
    canvas. This outer div is Livewire's, and the listeners hang off it: the
    browser events bubble up out of the ignored subtree, and are turned into
    Livewire events here.

    @script runs once when the component is initialised and again after a
    navigation, and it is not affected by wire:ignore -- which is exactly why the
    bridge lives here rather than inside the viewer.
--}}
<div>
    @if ($model)
        <x-pindle::viewer
            :for="$model"
            :document="$document"
            :height="$height"
            :readonly="$readonly"
        />
    @else
        <div class="pindle pindle--empty">
            <p>{{ __('There is no document to annotate yet.') }}</p>
        </div>
    @endif
</div>

@script
<script>
    // Two events, named in the design spec, so a parent component can react
    // server-side without listening for Pindle's Laravel events.
    for (const name of ['annotation-created', 'comment-posted']) {
        $wire.$el.addEventListener(`pindle:${name}`, (event) => {
            $wire.dispatch(`pindle:${name}`, { detail: event.detail });
        });
    }
</script>
@endscript
