{{--
    Home page: "everything your place needs", on navy. The systems are listed on the start side; the end side is a
    stage: a device in the middle of slow orbit rings, one chip per system on the rings, and the device's screen is the
    real screen of the system in focus. On wide screens the section is pinned while the visitor scrolls through it
    (landing.js, data-systems): the scroll picks the system, and the last step turns the device into an EgMenu cup.
    On phones, with reduced motion or without JS, the systems are cards (swiped sideways on phones) and the stage
    follows the card in view or the one tapped.
--}}
@php
    $systems = [
        ['qr', __('Menu and orders'), __('A QR menu in your guests’ languages, and orders from the table and your own link on one live board, with no commission.'), 'orders', 'app.egmenu.app/orders', null],
        ['cash', __('Cashier'), __('Quick sales on any tablet or laptop, shifts and a Z report, and it keeps working offline.'), 'pos', 'app.egmenu.app/pos', 'Enterprise'],
        ['chef', __('Kitchen'), __('A screen for every station, the bar and the kitchen, and a tap marks the order ready.'), 'kitchen', 'app.egmenu.app/kitchen', 'Enterprise'],
        ['phone', __('Call center and drivers'), __('Phone orders with the customer’s address, handed to your drivers and followed on the map.'), 'call-center', 'app.egmenu.app/call-center', 'Enterprise'],
        ['stamp', __('Loyalty and wallet'), __('A stamp card, a prepaid balance and offers that bring the guest back.'), 'loyalty-desk', 'app.egmenu.app/loyalty', null],
        ['chart', __('Reports'), __('Sales, payment methods, busy hours and the best sellers, day by day.'), 'reports', 'app.egmenu.app/reports', null],
    ];
    // where each chip sits on the outer ring: angle in degrees (0 = the top, clockwise)
    $chipAngles = [-60, 0, 60, 120, 180, 240];
@endphp
<section id="systems" data-systems class="hm-sys" style="--n: {{ count($systems) + 1 }}" aria-labelledby="systems-title">
    <div class="hm-sys-pin">
        <div class="mx-auto grid w-full max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-12">
            <div class="min-w-0 max-lg:contents">
                <div class="max-lg:order-1">
                <p class="hm-kicker">{{ __('One system') }}</p>
                <h2 id="systems-title" class="hm-h2 mt-4 text-white">{{ __('Everything your place needs.') }}</h2>
                <p class="hm-sub mt-4 max-w-xl">{{ __('From the menu on the table to the report at closing: one dashboard, in Arabic and English. Take what you need now, and add the rest when you grow.') }}</p>
                </div>

                <ol class="hm-sys-list max-lg:order-3 lg:mt-8" data-sys-list>
                    @foreach ($systems as $i => [$sysIcon, $sysTitle, $sysText, , , $sysTag])
                        <li>
                            <button type="button" data-sys-item="{{ $i }}" aria-controls="sys-stage" aria-current="{{ $i === 0 ? 'true' : 'false' }}" @class(['hm-sys-item', 'is-on' => $i === 0])>
                                <span class="flex items-center gap-2 text-base font-extrabold text-white">
                                    <x-landing.icon :name="$sysIcon" class="size-4 shrink-0 text-brand-500" />{{ $sysTitle }}
                                    @if ($sysTag)<span class="hm-tag hm-tag--dark">{{ $sysTag }}</span>@endif
                                </span>
                                <span class="mt-1 block text-sm leading-relaxed text-white/60">{{ $sysText }}</span>
                            </button>
                        </li>
                    @endforeach
                    <li>
                        <button type="button" data-sys-item="{{ count($systems) }}" aria-controls="sys-stage" aria-current="false" class="hm-sys-item hm-sys-item--end">
                            <span class="flex items-center gap-2 text-base font-extrabold text-white"><x-landing.icon name="cup" class="size-4 shrink-0 text-brand-500" />{{ __('And it is all one place: yours') }}</span>
                            <span class="mt-1 block text-sm leading-relaxed text-white/60">{{ __('Your name, your colours and your logo on the online menu and the printed one.') }}</span>
                        </button>
                    </li>
                </ol>
            </div>

            <div class="hm-sys-stage max-lg:order-2" id="sys-stage">
                <div class="hm-sys-rings" aria-hidden="true"><i data-ring="a"></i><i data-ring="b"></i><i data-ring="c"></i><b></b></div>
                @foreach ($systems as $i => [$sysIcon, $sysTitle])
                    <span class="hm-chip" data-sys-chip="{{ $i }}" style="--a: {{ $chipAngles[$i] }}deg" aria-hidden="true">
                        <span @class(['hm-chip-in', 'is-on' => $i === 0])><x-landing.icon :name="$sysIcon" class="size-3.5" />{{ $sysTitle }}</span>
                    </span>
                @endforeach
                <div class="hm-sys-device">
                    <div class="hm-sys-tablet">
                        @foreach ($systems as $i => [$sysIcon, $sysTitle, , $sysShot])
                            <img data-sys-screen="{{ $i }}" @class(['is-on' => $i === 0]) src="{{ versioned_asset("img/site/{$sysShot}-sm.webp") }}"
                                 srcset="{{ versioned_asset("img/site/{$sysShot}-sm.webp") }} 800w, {{ versioned_asset("img/site/{$sysShot}.webp") }} 1600w"
                                 sizes="(min-width: 1024px) 460px, 80vw" width="800" height="504" loading="lazy" decoding="async" alt="{{ $sysTitle }} — {{ __('real screen') }}">
                        @endforeach
                    </div>
                </div>
                {{-- the last step: the device becomes a take-away cup with the EgMenu mark --}}
                <div class="hm-cup" aria-hidden="true">
                    <svg viewBox="0 0 200 250" class="hm-cup-svg">
                        <defs>
                            <linearGradient id="hm-cup-body" x1="0" x2="1"><stop offset="0" stop-color="#dfe8f2"/><stop offset=".45" stop-color="#ffffff"/><stop offset="1" stop-color="#c9d6e4"/></linearGradient>
                            <linearGradient id="hm-cup-sleeve" x1="0" x2="1"><stop offset="0" stop-color="#00447c"/><stop offset=".5" stop-color="#005ba6"/><stop offset="1" stop-color="#0a2540"/></linearGradient>
                        </defs>
                        <path d="M40 58 L160 58 L146 236 Q145 244 136 244 L64 244 Q55 244 54 236 Z" fill="url(#hm-cup-body)"/>
                        <path d="M45.5 112 L154.5 112 L148.6 188 L51.4 188 Z" fill="url(#hm-cup-sleeve)"/>
                        <rect x="30" y="38" width="140" height="22" rx="10" fill="#f2f6fb"/>
                        <path d="M44 38 Q48 22 70 20 L130 20 Q152 22 156 38 Z" fill="#ffffff"/>
                        <rect x="88" y="24" width="24" height="5" rx="2.5" fill="#c3d5e8"/>
                    </svg>
                    <x-logo :size="40" :text="false" class="hm-cup-mark" />
                </div>
                <span class="hm-sys-progress" aria-hidden="true"><i data-sys-progress></i></span>
            </div>
        </div>
    </div>
</section>
