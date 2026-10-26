{{-- the offer advice (App\Services\Crm\OfferAdvisor): refreshed while the caller taps answers --}}
<div data-offer-body>
    @if ($advice['plan'])
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-navy-500">{{ __('Offer') }}</span>
            <span class="badge bg-navy-800 px-3 py-1 text-sm text-white" data-offer-plan="{{ $advice['plan']->code }}">{{ $advice['plan']->displayName() }}</span>
            @if ($advice['plan']->isContactSales())
                <span class="text-xs text-navy-500">{{ __('Priced with sales') }}</span>
            @elseif ((float) $advice['plan']->monthly_price > 0)
                <span class="text-xs text-navy-500">{{ __(':price EGP / month', ['price' => money($advice['plan']->monthly_price)]) }}</span>
            @endif
        </div>
    @endif
    <ul class="mt-2 space-y-1 text-sm text-navy-700">
        @foreach ($advice['why'] as $line)<li class="flex gap-2"><span class="text-navy-300" aria-hidden="true">•</span><span>{{ $line }}</span></li>@endforeach
    </ul>
    @if ($advice['features'])
        <div class="mt-3 text-xs font-bold text-navy-500">{{ __('Talk about') }}</div>
        <div class="mt-1 flex flex-wrap gap-1.5" data-offer-features>
            @foreach ($advice['features'] as $feature)
                <span class="badge bg-brand-50 text-brand-800 ring-1 ring-brand-200" data-feature="{{ $feature['key'] }}">{{ $feature['label'] }}</span>
            @endforeach
        </div>
    @endif
    @if ($advice['discount'])
        <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900" data-offer-discount="{{ $advice['discount']['percent'] }}">
            <div class="font-bold">{{ __('Discount: up to :pct%', ['pct' => $advice['discount']['percent']]) }}</div>
            <p class="mt-0.5">{{ $advice['discount']['why'] }}</p>
        </div>
    @endif
    @if ($advice['next'])
        <div class="mt-3 text-xs font-bold text-navy-500">{{ __('Next step') }}</div>
        <ul class="mt-1 space-y-1 text-sm text-navy-800">
            @foreach ($advice['next'] as $line)<li class="flex gap-2"><span class="text-brand-600" aria-hidden="true">←</span><span>{{ $line }}</span></li>@endforeach
        </ul>
    @endif
</div>
