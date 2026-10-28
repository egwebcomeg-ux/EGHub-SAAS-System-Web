{{--
    Call-center order panel. Wide screens (≥1280): two zones side by side, "customer & delivery" and "the order",
    with the totals and the send button always on screen. Narrower screens: three steps (customer → delivery → order)
    over one sticky footer. resources/js/pos.js (CC mode) drives it through the data-* hooks.
--}}
@php
    $zoneMode = $data['zoneMode'] ?? 'manual';
    $byDistance = ($data['pricing']['method'] ?? 'zones') === 'distance';
@endphp
<aside id="pos-ticket" class="pos-ticket flex w-[24rem] shrink-0 flex-col overflow-hidden border-s border-navy-100 bg-white max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-40 max-md:h-[92dvh] max-md:w-full max-md:rounded-t-3xl max-md:border-s-0 max-md:shadow-2xl xl:w-[42rem] min-[87.5rem]:w-[48rem]" data-ticket data-cc-panel data-cc-step="customer">
    <div class="flex items-center justify-between border-b border-navy-100 px-4 py-1.5 md:hidden">
        <span class="font-extrabold">🧾 {{ __('The order') }}</span>
        <button type="button" class="grid size-11 place-items-center rounded-xl text-navy-600 hover:bg-navy-50" data-ticket-close aria-label="{{ __('Close') }}">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- narrower screens: the three steps; the order step carries the item count --}}
    <nav class="grid shrink-0 grid-cols-3 gap-1 border-b border-navy-100 bg-navy-50 p-1.5 xl:hidden" role="tablist" aria-label="{{ __('Call steps') }}" data-cc-tabs>
        @foreach (['customer' => ['١', __('Customer')], 'delivery' => ['٢', __('Delivery')], 'order' => ['٣', __('The order')]] as $key => [$num, $label])
            <button type="button" role="tab" class="flex min-h-11 min-w-0 items-center justify-center gap-1.5 rounded-xl px-1 text-sm font-bold text-navy-600" data-cc-tab="{{ $key }}" aria-controls="cc-pane-{{ $key }}">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-white text-xs font-extrabold ring-1 ring-navy-200" data-cc-tab-num>{{ $num }}</span>
                <span class="truncate">{{ $label }}</span>
                @if ($key === 'order')
                    <span class="badge shrink-0 bg-brand-600 text-white" data-cc-count>0</span>
                @endif
            </button>
        @endforeach
    </nav>

    <div class="flex min-h-0 flex-1 flex-col xl:grid xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] xl:grid-rows-[minmax(0,1fr)_auto]">
        {{-- zone 1: the caller and where the order goes --}}
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain xl:col-start-1 xl:row-span-2 xl:row-start-1 xl:border-e xl:border-navy-100 xl:bg-navy-50/40" data-cc-setup>
            <h2 class="hidden px-4 pt-3 text-xs font-extrabold tracking-wide text-navy-500 xl:block">{{ __('Customer & delivery') }}</h2>

            <section id="cc-pane-customer" class="space-y-2 p-3" data-cc-pane="customer">
                <div class="space-y-1" data-caller-search>
                    <label class="text-sm font-bold text-navy-700" for="cc-phone">{{ __('Caller mobile') }}</label>
                    <div class="flex gap-2">
                        <input id="cc-phone" class="field min-h-12 min-w-0 flex-1 text-xl font-bold tracking-wide" data-customer-phone placeholder="01xxxxxxxxx" maxlength="20" dir="ltr" inputmode="tel" autocomplete="off" autofocus>
                        <button type="button" class="btn-primary min-h-12 shrink-0 px-5" data-lookup>{{ __('Search') }}</button>
                    </div>
                </div>
                <div class="hidden" data-caller aria-live="polite"></div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1" data-customer-fields>
                    <input class="field min-h-11" data-customer-name placeholder="{{ __('Customer name') }}" maxlength="100" aria-label="{{ __('Customer name') }}">
                    <input class="field min-h-11" data-phone2 placeholder="{{ __('Another phone (optional)') }}" maxlength="20" dir="ltr" inputmode="tel" aria-label="{{ __('Another phone (optional)') }}">
                </div>
                <button type="button" class="btn-ghost min-h-11 w-full xl:hidden" data-cc-next="delivery">{{ __('Next: delivery') }} ←</button>
            </section>

            <section id="cc-pane-delivery" class="space-y-3 p-3 max-xl:pt-3 xl:border-t xl:border-navy-100" data-cc-pane="delivery">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-navy-50 p-1 xl:bg-white xl:ring-1 xl:ring-navy-100">
                    <button type="button" class="btn min-h-11 whitespace-nowrap rounded-lg px-2 text-sm" data-type="delivery">🛵 {{ __('Delivery') }}</button>
                    <button type="button" class="btn min-h-11 whitespace-nowrap rounded-lg px-2 text-sm" data-type="takeaway">🛍 {{ __('Pickup') }}</button>
                </div>
                @include('partials.leaflet')
                <div class="space-y-2" data-delivery-box>
                    <div class="hidden space-y-1" data-addresses-box>
                        <div class="text-xs font-bold text-navy-500">{{ __('Saved addresses') }}</div>
                        <div class="flex flex-wrap gap-2" data-addresses></div>
                    </div>
                    {{-- the zone id that is sent; in manual mode it follows the two pickers below --}}
                    @if ($byDistance)
                        {{-- priced by distance: find the customer's place (typed address → suggestions, saved address, pin), or a ring --}}
                        <div class="space-y-2" data-dist-box>
                            <div class="flex items-center justify-between gap-2">
                                <label class="text-sm font-extrabold text-navy-800" for="cc-addr-q">📍 {{ __('Find the customer\'s place') }}</label>
                                <span class="text-xs text-navy-500">{{ __('Priced by distance from :branch', ['branch' => $data['branchName'] ?? '']) }}</span>
                            </div>
                            <div class="relative" data-addr-search>
                                <div class="flex gap-2">
                                    <input id="cc-addr-q" type="search" class="field min-h-12 min-w-0 flex-1 text-base" data-addr-q maxlength="100" autocomplete="off" enterkeyhint="search"
                                        placeholder="{{ __('Type the customer\'s address') }}" role="combobox" aria-expanded="false" aria-controls="cc-addr-results" aria-autocomplete="list">
                                    <button type="button" class="btn-ghost size-12 shrink-0 p-0 text-lg" data-map-toggle aria-pressed="true" title="{{ __('Pin on the map') }}" aria-label="{{ __('Pin on the map') }}">🗺</button>
                                </div>
                                <div id="cc-addr-results" class="absolute inset-x-0 top-full z-[1200] mt-1 hidden max-h-72 overflow-y-auto rounded-xl border border-navy-100 bg-white shadow-xl" role="listbox" data-addr-results></div>
                            </div>
                            <div class="relative h-56 overflow-hidden rounded-xl border border-navy-100 xl:h-64" dir="ltr" data-cc-map></div>
                            <p class="text-xs text-navy-500" data-map-hint>{{ __('Drag the pin to the customer\'s door, or tap the map.') }}</p>
                            <label class="flex items-center gap-2 text-sm">
                                <span class="shrink-0 font-semibold text-navy-600">{{ __('No pin? Rough distance') }}</span>
                                <select class="field min-h-11 min-w-0 flex-1" data-ring aria-label="{{ __('Delivery distance') }}">
                                    <option value="">{{ __('Choose the distance') }}</option>
                                    @foreach ($data['pricing']['rings'] ?? [] as $i => $ring)
                                        <option value="{{ $i }}">{{ __('Up to :km km', ['km' => rtrim(rtrim(number_format($ring['km'], 1), '0'), '.')]) }} · {{ (float) $ring['fee'] }} {{ $data['currency'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <select class="hidden" data-zone aria-hidden="true" tabindex="-1"><option value=""></option></select>
                    @elseif ($zoneMode === 'manual')
                        <div class="flex gap-2">
                            <div class="grid min-w-0 flex-1 grid-cols-2 gap-2">
                                <select class="field min-h-11 min-w-0" data-zone-root aria-label="{{ __('Area') }}"><option value="">{{ __('Area') }}</option></select>
                                <select class="field min-h-11 min-w-0" data-zone-sub aria-label="{{ __('Street / sub-area') }}" disabled><option value="">{{ __('Street / sub-area') }}</option></select>
                            </div>
                            <button type="button" class="btn-ghost size-11 shrink-0 p-0" data-map-toggle title="{{ __('Pin on the map') }}" aria-label="{{ __('Pin on the map') }}">📍</button>
                        </div>
                        <select class="hidden" data-zone aria-hidden="true" tabindex="-1"><option value="">{{ __('Choose the delivery zone') }}</option></select>
                    @else
                        <div class="flex gap-2">
                            <select class="field min-h-11 min-w-0 flex-1" data-zone aria-label="{{ __('Choose the delivery zone') }}"><option value="">{{ __('Choose the delivery zone') }}</option></select>
                            <button type="button" class="btn-ghost size-11 shrink-0 p-0" data-map-toggle title="{{ __('Pin on the map') }}" aria-label="{{ __('Pin on the map') }}">📍</button>
                        </div>
                    @endif
                    @unless ($byDistance)
                        <div class="hidden h-56 overflow-hidden rounded-xl border border-navy-100" dir="ltr" data-cc-map></div>
                    @endunless
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-navy-50 px-3 py-2 text-sm text-navy-700 xl:bg-white xl:ring-1 xl:ring-navy-100" data-zone-facts>
                        <span class="text-navy-500">{{ __('Choose the area to see the fee and the minimum order.') }}</span>
                    </div>
                    <p class="hidden text-xs font-bold text-emerald-700" data-pinned></p>
                    <p class="hidden rounded-xl bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800 ring-1 ring-amber-300" role="status" data-min-warning></p>
                    <textarea class="field min-h-20 py-2" rows="3" data-address maxlength="300" placeholder="{{ __('Street, building, floor, apartment, landmark') }}" aria-label="{{ __('Delivery address') }}"></textarea>
                    <details class="group text-sm" data-address-more>
                        <summary class="flex min-h-11 cursor-pointer items-center gap-1 font-semibold text-navy-600">{{ __('More details') }} <span class="text-xs text-navy-400 group-open:hidden">({{ __('address name') }})</span></summary>
                        <input class="field min-h-11" data-address-label maxlength="40" placeholder="{{ __('Address name, e.g. Home or Work (optional)') }}">
                    </details>
                </div>
                <p class="hidden rounded-xl bg-navy-50 px-3 py-2 text-sm font-semibold text-navy-700" data-pickup-note>🛍 {{ __('The customer picks the order up from :branch.', ['branch' => $data['branchName'] ?? '']) }}</p>
                <button type="button" class="btn-ghost min-h-11 w-full xl:hidden" data-cc-next="order">{{ __('Next: the order') }} ←</button>
            </section>
        </div>

        {{-- zone 2: the order itself --}}
        <div id="cc-pane-order" class="flex min-h-0 flex-1 flex-col xl:col-start-2 xl:row-start-1" data-cc-order>
            <h2 class="hidden items-center gap-2 px-4 pt-3 text-xs font-extrabold tracking-wide text-navy-500 xl:flex">{{ __('The order') }} <span class="badge bg-brand-600 text-white" data-cc-count>0</span></h2>
            <button type="button" class="mx-3 mt-2 block rounded-xl bg-navy-50 px-3 py-2 text-start text-sm ring-1 ring-navy-100" data-cc-summary data-cc-goto="delivery"></button>
            <ul class="min-h-24 flex-1 divide-y divide-navy-50 overflow-y-auto overscroll-contain px-3" data-lines></ul>
            <div class="space-y-2 border-t border-navy-100 px-3 py-2 text-sm">
                <input class="field min-h-11" data-notes placeholder="{{ __('Notes for the order') }}" maxlength="500" aria-label="{{ __('Notes for the order') }}">
                @if ($data['canDiscount'])
                    <div class="flex items-center gap-2">
                        <span class="text-navy-500">{{ __('Discount') }}</span>
                        <input class="field min-h-11 w-24" type="number" min="0" step="0.5" data-discount value="0" aria-label="{{ __('Discount') }}">
                        <select class="field min-h-11 w-20" data-discount-kind aria-label="{{ __('Discount') }}"><option value="amount">{{ __('EGP') }}</option><option value="percent">%</option></select>
                    </div>
                @endif
            </div>
        </div>

        {{-- always on screen: what is being sent, the totals and the send button --}}
        <footer class="shrink-0 space-y-2 border-t border-navy-100 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-6px_16px_-10px_rgba(10,37,64,0.35)] xl:col-start-2 xl:row-start-2" data-cc-footer>
            <button type="button" class="block w-full rounded-xl bg-navy-50 px-3 py-2 text-start text-sm ring-1 ring-navy-100 xl:hidden" data-cc-summary data-cc-goto="delivery" data-cc-footer-summary></button>
            <p class="hidden rounded-xl bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800 ring-1 ring-amber-300" role="status" data-min-warning-footer></p>
            <div class="space-y-0.5 text-sm" data-totals></div>
            <div class="flex gap-2">
                <button type="button" class="btn-ghost min-h-12 shrink-0 px-4 text-red-600" data-clear>{{ __('Clear') }}</button>
                <button type="button" class="btn-primary min-h-12 flex-1 justify-between text-base" data-send>
                    <span>{{ __('Place order') }}</span>
                    <span class="font-extrabold" data-send-total></span>
                </button>
            </div>
        </footer>
    </div>
</aside>
