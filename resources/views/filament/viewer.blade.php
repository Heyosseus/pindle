{{--
    Filament's field and entry both render this, and it renders the Blade
    component. One viewer implementation, three ways in -- a second one written
    for Filament would be a second place for the coordinate handling to drift.

    No field wrapper: the field and the entry name theirs differently, and this
    view is deliberately shared by both. What a wrapper would add here is a label
    above a viewer that already has a toolbar of its own.
--}}
@php
    $record = $getViewerRecord();
@endphp

<div>
    @if ($record)
        <x-pindle::viewer
            :for="$record"
            :document="$getDocumentKey()"
            :height="$getViewerHeight()"
            :readonly="$isViewerReadonly()"
        />
    @else
        <div class="pindle pindle--empty">
            <p>{{ __('Save this record before annotating its document.') }}</p>
        </div>
    @endif
</div>
