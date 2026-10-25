{{-- How many TVs the restaurant pairs out of what its plan and add-ons allow, with «زوّد شاشة» once it is full. --}}
<div class="rounded-xl bg-navy-50 p-3" data-tv-limit>
    <x-usage-bar :label="__('Paired screens')" :used="$paired" :limit="$limit" />
    @if ($limit !== null && $paired >= $limit)
        <p class="mt-2 text-xs text-navy-600">{{ __('All your screens are paired. Each extra screen is a small monthly add-on.') }}</p>
    @endif
    @can('billing.manage')
        <a href="{{ route('dash.billing') }}#addon-screens" class="btn-ghost mt-2 min-h-9 px-3 text-sm" data-add-screen>＋ {{ __('Add a screen') }}</a>
    @else
        @if ($limit !== null && $paired >= $limit)<p class="mt-1 text-xs text-navy-500">{{ __('Ask the owner to add a screen from the subscription page.') }}</p>@endif
    @endcan
</div>
