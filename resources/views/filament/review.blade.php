{{--
    The review summary, as badges. Rendered by both the table column and the
    infolist entry -- one view, so the index screen and the record page can
    never say different things about the same document.
--}}
@php
    $review = $getReview();
@endphp

<div class="pindle-review">
    @if (! $review || $review->isEmpty())
        <span class="pindle-review__badge pindle-review__badge--quiet">{{ __('No marks') }}</span>
    @else
        @if ($review->open > 0)
            <span class="pindle-review__badge pindle-review__badge--open">
                {{ trans_choice('{1} :count open|[2,*] :count open', $review->open, ['count' => $review->open]) }}
            </span>
        @endif

        @if ($review->orphaned > 0)
            <span
                class="pindle-review__badge pindle-review__badge--orphaned"
                title="{{ __('Made on a version of this document that has since been replaced.') }}"
            >
                {{ trans_choice('{1} :count orphaned|[2,*] :count orphaned', $review->orphaned, ['count' => $review->orphaned]) }}
            </span>
        @endif

        @if ($review->isSettled())
            <span class="pindle-review__badge pindle-review__badge--settled">{{ __('Settled') }}</span>
        @endif
    @endif
</div>
