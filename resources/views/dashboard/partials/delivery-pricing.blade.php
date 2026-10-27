{{-- How one branch prices delivery: its zones, or the straight-line distance from its pin with rings. The map previews
     the rings or the drawn zones; "try an address" drops a test pin and shows what a guest there would pay
     (resources/js/delivery-pricing.js, the same sums as App\Services\Delivery\DeliveryPricing). --}}
@php
    $method = \App\Services\Delivery\DeliveryPricing::method($branch);
    $rings = \App\Services\Delivery\DeliveryPricing::rings($branch);
    $branchZones = $zones->where('branch_id', $branch->id)->where('is_active', true);
    // a new distance setup starts from a common shape: up to 2 km, 2–4 km, 4–6 km
    $shownRings = $rings ?: [['km' => 2, 'fee' => '', 'min' => '', 'minutes' => ''], ['km' => 4, 'fee' => '', 'min' => '', 'minutes' => ''], ['km' => 6, 'fee' => '', 'min' => '', 'minutes' => '']];
    $pricingData = [
        'method' => $method,
        'pin' => $branch->lat !== null && $branch->lng !== null ? [(float) $branch->lat, (float) $branch->lng] : null,
        'fallback' => $center,
        'zones' => $branchZones->map(fn ($z) => [
            'id' => $z->id, 'name' => $z->label(), 'parent' => $z->parent_id, 'fee' => (float) ($z->effective('fee') ?? 0),
            'min' => (float) ($z->effective('min_order') ?? 0), 'eta' => $z->effective('estimated_minutes'),
            'polygon' => is_array($z->polygon) && count($z->polygon) >= 3 ? $z->polygon : null,
        ])->values(),
        'currency' => tr(\App\Services\Settings::get('currency'), \App\Services\Settings::get('currency_en')),
        't' => [
            'km' => __(':km km from the restaurant', ['km' => '__K__']), 'fee' => __('Delivery :fee', ['fee' => '__A__']),
            'min' => __('Minimum :amount', ['amount' => '__A__']), 'eta' => __('About :n min', ['n' => '__N__']),
            'outside' => __('Not delivered: outside your delivery area.'), 'pin' => __('Your restaurant'),
            'upTo' => __('Up to :km km', ['km' => '__K__']), 'test' => __('Test address'),
            'noZone' => __('No drawn zone holds this point.'), 'noRings' => __('Add at least one distance first.'),
            'noPin' => __('Put your restaurant’s pin on the map first.'),
        ],
    ];
@endphp
<section class="card space-y-4" data-pricing>
    <form method="post" action="{{ route('dash.zones.pricing', $branch) }}" class="space-y-4" data-pricing-form>
        @csrf
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title">💸 {{ __('How is delivery priced?') }}@if ($branches->count() > 1) <span class="block text-base text-navy-500">{{ $branch->name }}</span>@endif</h2>
        </div>
        <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
            @foreach (['zones' => ['🗺️', __('By zones'), __('Each area has its own fee. The guest pins the map (when the zones are drawn) or picks the area from the list.')], 'distance' => ['📏', __('By distance'), __('Set your restaurant’s pin and distance rings, e.g. up to 2 km = 25. The guest pins the map and the fee follows the distance.')]] as $key => [$icon, $title, $text])
                <label class="card flex cursor-pointer items-start gap-3 transition has-[:checked]:ring-2 has-[:checked]:ring-brand-600">
                    <input type="radio" name="method" value="{{ $key }}" @checked($method === $key) class="mt-1 size-4 accent-brand-600" data-pricing-method>
                    <span><span class="block font-extrabold"><span aria-hidden="true">{{ $icon }}</span> {{ $title }}</span><span class="mt-1 block text-sm text-navy-500">{{ $text }}</span></span>
                </label>
            @endforeach
        </div>

        <div @class(['space-y-3', 'hidden' => $method !== 'distance']) data-distance-fields>
            <div class="rounded-xl bg-navy-50 p-3 text-sm text-navy-700">
                📍 {{ __('Drag the restaurant pin to your exact door on the map, or tap the map to move it there.') }}
                <label class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="font-semibold">{{ __('Coordinates') }}</span>
                    <input class="field min-h-10 w-56 py-1 text-sm" dir="ltr" inputmode="decimal" placeholder="30.7865, 31.0004" value="{{ $pricingData['pin'] ? $pricingData['pin'][0].', '.$pricingData['pin'][1] : '' }}" data-pin-coords aria-describedby="coords-help-{{ $branch->id }}">
                </label>
                <span id="coords-help-{{ $branch->id }}" class="mt-1 block text-xs text-navy-500">{{ __('You can paste them from Google Maps (long press on your restaurant, then copy the numbers).') }}</span>
                <input type="hidden" name="lat" value="{{ $pricingData['pin'][0] ?? '' }}" data-pin-lat>
                <input type="hidden" name="lng" value="{{ $pricingData['pin'][1] ?? '' }}" data-pin-lng>
            </div>
            <div class="space-y-2">
                <div class="grid grid-cols-[repeat(4,minmax(0,1fr))_2.25rem] gap-2 text-xs font-bold text-navy-600">
                    <span>{{ __('Up to (km)') }}</span><span>{{ __('Delivery fee') }}</span><span>{{ __('Minimum order') }}</span><span>{{ __('Minutes') }}</span><span></span>
                </div>
                <div class="space-y-2" data-rings>
                    @foreach ($shownRings as $i => $r)
                    <div class="grid grid-cols-[repeat(4,minmax(0,1fr))_2.25rem] items-center gap-2" data-ring>
                        <input class="field min-h-10 px-2 py-1" type="number" step="0.1" min="0.1" max="{{ \App\Services\Delivery\DeliveryPricing::MAX_KM }}" name="rings[{{ $i }}][km]" value="{{ $r['km'] }}" required aria-label="{{ __('Up to (km)') }}" data-ring-km>
                        <input class="field min-h-10 px-2 py-1" type="number" step="0.5" min="0" name="rings[{{ $i }}][fee]" value="{{ $r['fee'] }}" required aria-label="{{ __('Delivery fee') }}" data-ring-fee>
                        <input class="field min-h-10 px-2 py-1" type="number" step="1" min="0" name="rings[{{ $i }}][min]" value="{{ $r['min'] ?: '' }}" placeholder="0" aria-label="{{ __('Minimum order') }}" data-ring-min>
                        <input class="field min-h-10 px-2 py-1" type="number" min="5" max="300" name="rings[{{ $i }}][minutes]" value="{{ $r['minutes'] }}" aria-label="{{ __('Minutes') }}" data-ring-minutes>
                        <button type="button" class="min-h-10 text-sm text-red-600" data-ring-remove aria-label="{{ __('Remove') }}">✕</button>
                    </div>
                    @endforeach
                </div>
            </div>
            <button type="button" class="btn-ghost min-h-10 px-3 text-sm" data-ring-add>+ {{ __('Add a distance') }}</button>
            <p class="text-xs text-navy-500">📏 {{ __('The distance is a straight line from your restaurant (as the crow flies), not the driving route: keep the rings a little shorter than the real road. Farther than the last ring is not delivered.') }}</p>
        </div>
        @if ($method === 'zones' || $branchZones->isNotEmpty())
            <p @class(['text-xs text-navy-500', 'hidden' => $method !== 'distance']) data-distance-zones-note>{{ __('Your active zones stay as a list for the guest who prefers not to use the map. Turn them off below to keep the map only.') }}</p>
        @endif

        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn-ghost min-h-10 px-3 text-sm" aria-pressed="false" data-test-toggle>🧪 {{ __('Try an address') }}</button>
                <span class="text-sm text-navy-500" data-test-hint>{{ __('Turn it on, then tap anywhere on the map to see what a guest there pays.') }}</span>
            </div>
            <div class="relative z-0 h-[360px] overflow-hidden rounded-xl border border-navy-100" dir="ltr" data-pricing-map></div>
            <p class="hidden rounded-xl px-3 py-2 text-sm font-semibold" aria-live="polite" data-test-result></p>
        </div>

        <button class="btn-primary w-full sm:w-auto">{{ __('Save the delivery pricing') }}</button>
        <template data-ring-template>
                    <div class="grid grid-cols-[repeat(4,minmax(0,1fr))_2.25rem] items-center gap-2" data-ring>
                        <input class="field min-h-10 px-2 py-1" type="number" step="0.1" min="0.1" max="{{ \App\Services\Delivery\DeliveryPricing::MAX_KM }}" name="rings[__I__][km]" required aria-label="{{ __('Up to (km)') }}" data-ring-km>
                        <input class="field min-h-10 px-2 py-1" type="number" step="0.5" min="0" name="rings[__I__][fee]" required aria-label="{{ __('Delivery fee') }}" data-ring-fee>
                        <input class="field min-h-10 px-2 py-1" type="number" step="1" min="0" name="rings[__I__][min]"  placeholder="0" aria-label="{{ __('Minimum order') }}" data-ring-min>
                        <input class="field min-h-10 px-2 py-1" type="number" min="5" max="300" name="rings[__I__][minutes]"  aria-label="{{ __('Minutes') }}" data-ring-minutes>
                        <button type="button" class="min-h-10 text-sm text-red-600" data-ring-remove aria-label="{{ __('Remove') }}">✕</button>
                    </div>
        </template>
        <script type="application/json" data-pricing-data>{!! json_encode($pricingData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </form>
</section>
