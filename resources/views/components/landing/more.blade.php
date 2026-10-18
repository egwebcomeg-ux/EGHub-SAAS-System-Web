@props(['for'])
{{-- "Show all" under a list whose extra items carry data-more-item (phones and tablets only; landing.js toggles it). --}}
<div {{ $attributes->merge(['class' => 'flex lg:hidden' . ($attributes->has('class') ? '' : ' mt-6 justify-center')]) }}>
    <button type="button" data-more-btn aria-controls="{{ $for }}" aria-expanded="false" data-all="{{ __('Show all') }}" data-less="{{ __('Show less') }}"
            class="btn-ghost min-h-11 rounded-full px-5 font-bold">
        <span data-more-label>{{ __('Show all') }}</span>
        <svg class="lp-chev size-4 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
    </button>
</div>
