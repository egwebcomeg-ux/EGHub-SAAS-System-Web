{{-- Intro splash (setting menu_intro) and the first-open sheet (order type + branch); shown by the classes intro-head sets. --}}
@php
    $wayButtons = [
        'dine' => ['🍽', __('In the place / the hall')],
        'takeaway' => ['🛍', __('Takeaway')],
        'delivery' => ['🛵', __('Delivery order')],
    ];
    $introTagline = trim(tr($settings['tagline'], $settings['tagline_en']));
@endphp
@if ($intro !== 'off')
    <div class="m-intro m-intro--{{ $intro }}" data-intro aria-hidden="true">
        <div class="m-intro-glow"></div>
        @if ($intro === 'full')
            <div class="m-intro-pattern"></div>
            <div class="m-intro-ring"></div>
        @endif
        <div class="m-intro-stage">
            @if ($settings['logo'])
                <img src="/storage/{{ $settings['logo'] }}" alt="" @class(['m-intro-logo', 'm-intro-logo--bare' => ! ($logoTile ?? true)]) fetchpriority="low">
            @else
                <span class="m-intro-name">{{ $brand }}</span>
            @endif
            @if ($introTagline !== '')
                <p class="m-intro-tagline">{{ $introTagline }}</p>
            @endif
        </div>
        <span class="m-intro-skip">{{ __('Tap to skip') }}</span>
    </div>
@endif
@if ($firstSheet ?? false)
    {{--
        The one first-open sheet, after the intro: how the guest orders (2+ ways) on top and, with 2+ branches and no
        table QR, the branch list (nearest first when the location is already allowed, else a "nearest branch" button),
        then one confirm. It opens by itself once (setting order_type_prompt, or a first visit with no branch chosen,
        intro-head) and again from the chips in the header. The popup banner waits until it is closed.
    --}}
    <div class="m-choice" data-order-choice data-first-sheet role="dialog" aria-modal="true" aria-labelledby="order-choice-title">
        <div @class(['m-choice-card m-surface relative', 'm-choice-card--closable' => ! $waysMenu])>
            <button type="button" class="m-choice-close m-muted absolute end-4 top-3 text-2xl leading-none" data-choice-close aria-label="{{ __('Close') }}">×</button>
            @if ($settings['logo'])
                <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" @class(['h-16 w-auto max-w-36 object-contain', 'rounded-2xl bg-white p-1 shadow' => $logoTile ?? true])>
            @else
                <span class="text-xl font-black">{{ $brand }}</span>
            @endif
            <h2 id="order-choice-title" class="text-2xl font-black">{{ $waysMenu ? __('How will you order?') : '📍 ' . __('Choose the branch') }}</h2>
            @if ($waysMenu)
                <p class="m-muted -mt-2 text-sm">{{ __('Choose how you want your order to continue.') }}</p>
                <div class="grid w-full gap-2.5" role="group" aria-labelledby="order-choice-title" data-way-pick>
                    @foreach ($ways as $way)
                        <button type="button" @class(['m-way-option flex min-h-14 w-full items-center justify-center gap-3 rounded-2xl border text-lg font-extrabold', 'm-way-on' => $loop->first, 'm-surface-2 m-border' => ! $loop->first])
                            data-way="{{ $way }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}"><span aria-hidden="true">{{ $wayButtons[$way][0] }}</span> {{ $wayButtons[$way][1] }}</button>
                    @endforeach
                </div>
            @endif
            @if ($branchPicker)
                {{-- the branch part: rows rebuilt by resources/js/menu.js (open now, distance, map); links work without it --}}
                <section class="w-full text-start" data-branch-sheet @if ($waysMenu) aria-labelledby="branch-sheet-title" @endif>
                    @if ($waysMenu)
                        <h3 id="branch-sheet-title" class="mt-1 text-lg font-extrabold">📍 {{ __('From which branch?') }}</h3>
                    @endif
                    <p class="m-muted mb-2 text-sm">{{ __('Prices and what is available follow the branch you choose.') }}</p>
                    @if ($branches->contains(fn ($b) => $b->lat !== null))
                        <button type="button" class="m-border m-primary-text min-h-11 w-full rounded-2xl border font-extrabold" data-branch-near>🧭 {{ __('Nearest branch to me') }}</button>
                    @endif
                    <p class="m-muted mt-2 hidden text-center text-xs" data-branch-note></p>
                    <div class="mt-2 space-y-2" role="group" aria-label="{{ __('Choose the branch') }}" data-branch-list>
                        @foreach ($branches as $b)
                            <a href="?branch={{ $b->slug }}" @class(['m-border block rounded-2xl border p-3', 'm-primary-border' => $b->id === $branch->id]) data-branch-row="{{ $b->id }}" data-branch-pick="{{ $b->slug }}">
                                <b class="block">{{ tr($b->name, $b->name_en) }}</b>
                                @if ($b->address)<span class="m-muted block text-sm">{{ $b->address }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            <button type="button" class="m-primary min-h-14 w-full rounded-2xl text-lg font-extrabold shadow" data-first-confirm>{{ __("Let's order") }}</button>
            @if ($waysMenu)
                <p class="m-muted -mt-1 text-xs">{{ __('You can change it later from your cart.') }}</p>
            @endif
        </div>
    </div>
@endif
