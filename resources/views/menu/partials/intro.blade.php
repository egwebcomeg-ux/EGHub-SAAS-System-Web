{{-- Intro splash (setting menu_intro) and the first-open sheet (order type + branch); shown by the classes intro-head sets. --}}
@php
    // the order types as tiles: an icon, the short name and one short line (lucide-style strokes, currentColor)
    $wayCards = [
        'dine' => [__('In the place'), __('Order to your table'),
            '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>'],
        'takeaway' => [__('Takeaway'), __('Ready for you to pick up'),
            '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
        'delivery' => [__('Delivery'), __('We bring it to your door'),
            '<circle cx="5.5" cy="17.5" r="3"/><circle cx="18.5" cy="17.5" r="3"/><path d="M8.5 17.5h6.5l-2-7H9"/><path d="M15 6h2.5l2.5 8.5"/><path d="M3 11h5"/>'],
    ];
    $introTagline = trim(tr($settings['tagline'], $settings['tagline_en']));
    $introLight = ($introBackground ?? 'brand') === 'light';
@endphp
@if ($intro === 'mascot')
    @php
        $mascot = \App\Services\BrandAssets::url($settings['intro_mascot']);
        $mascotSpeed = \App\Services\BrandAssets::url($settings['intro_mascot_speed'] ?? '');
        $mascotSlogan = \App\Services\BrandAssets::url($settings['intro_slogan'] ?? '');
        $mascotPattern = \App\Services\BrandAssets::url($settings['intro_pattern'] ?? '');
    @endphp
    {{-- the brand character rolls in spinning (mirrored in RTL), its speed lines trail and settle, it lands with a
         squash, a spark pops, the logo rises, the slogan pops, then the character floats until the screen fades --}}
    <div class="m-intro m-intro--mascot" data-intro data-intro-style="mascot" aria-hidden="true">
        @if ($mascotPattern)
            <div class="m-mascot-pattern" style="background-image: url('{{ $mascotPattern }}')" data-mascot-pattern></div>
        @endif
        <div class="m-mascot-glow"></div>
        <div class="m-mascot-stage">
            <div class="m-char">
                <div class="m-char-float"><div class="m-char-move"><div class="m-char-squash">
                    @if ($mascotSpeed)<img src="{{ $mascotSpeed }}" alt="" class="m-char-speed" data-mascot-speed>@endif
                    <img src="{{ $mascot }}" alt="" class="m-char-bean" data-mascot>
                </div></div></div>
                <span class="m-char-spark">✦</span>
            </div>
            @if ($settings['logo'])
                <img src="/storage/{{ $settings['logo'] }}" alt="" class="m-mascot-logo" fetchpriority="low">
            @else
                <span class="m-mascot-logo m-mascot-name">{{ $brand }}</span>
            @endif
            @if ($mascotSlogan)
                <img src="{{ $mascotSlogan }}" alt="" class="m-mascot-slogan" data-mascot-slogan>
            @elseif ($introTagline !== '')
                <p class="m-mascot-slogan m-mascot-tagline">{{ $introTagline }}</p>
            @endif
        </div>
        <span class="m-intro-skip">{{ __('Tap to skip') }}</span>
    </div>
@elseif ($intro !== 'off')
    <div @class(['m-intro m-intro--' . $intro, 'm-intro--light' => $introLight]) data-intro data-intro-background="{{ $introLight ? 'light' : 'brand' }}" aria-hidden="true">
        <div class="m-intro-glow"></div>
        @if ($intro === 'full')
            <div class="m-intro-pattern"></div>
        @endif
        @if ($intro === 'full' || $introLight)
            <div class="m-intro-ring"></div>
        @endif
        <div class="m-intro-stage">
            @if ($settings['logo'])
                {{-- on the light screen the logo shows as drawn (a dark logo on the page colour) --}}
                <img src="/storage/{{ $settings['logo'] }}" alt="" @class(['m-intro-logo', 'm-intro-logo--bare' => $introLight || ! ($logoTile ?? true)]) fetchpriority="low">
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
        The one sheet, short and light: the logo and the question on one line, with 2+ branches (no table QR) the
        branch list (a search from 5 branches, a small "nearest to me" that asks for the location only on a tap, nearest
        first when it is already allowed) that scrolls on its own, then the order types as a row of tiles that always
        stays on screen: tapping one continues at once (another branch reloads its menu). With one way to order,
        tapping a branch continues. It opens by itself only on a first visit to a place with 2+ branches (intro-head);
        the chips in the header and the first item added (setting order_type_prompt) open it too. The menu shows
        blurred behind it; the popup banner waits until it is closed.
    --}}
    @php
        $wayCount = count($sheetWays);
        // on the menu link the hall is a list for the waiter (sending from the hall needs the table QR)
        $wayCards['dine'][1] = $hall['mode'] === 'list' ? __('Choose, then show the waiter') : $wayCards['dine'][1];
    @endphp
    <div class="m-choice" data-order-choice data-first-sheet role="dialog" aria-modal="true" aria-labelledby="order-choice-title">
        <div @class(['m-choice-card m-surface', 'm-choice-card--closable' => ! $waysMenu])>
            <div class="m-choice-head m-choice-in">
                @if ($settings['logo'])
                    <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" @class(['m-choice-logo', 'm-choice-logo--tile' => $logoTile ?? true])>
                @else
                    <span class="m-choice-logo m-choice-logo--name m-primary" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
                @endif
                <div class="min-w-0 flex-1">
                    {{-- the name only where no logo shows it --}}
                    <span @class(['m-choice-brand truncate', 'sr-only' => (bool) $settings['logo']])>{{ $brand }}</span>
                    <h2 id="order-choice-title" class="text-xl font-black leading-tight">{{ $waysMenu ? __('How will you order?') : __('Choose the branch') }}</h2>
                </div>
                <button type="button" class="m-choice-close size-10 place-items-center rounded-full text-2xl leading-none" data-choice-close aria-label="{{ __('Close') }}">×</button>
            </div>
            @if ($branchPicker)
                {{-- the branch part: rows rebuilt by resources/js/menu.js (open now, distance, map); links work without it --}}
                <section class="m-choice-branch m-choice-in" style="--i: 1" data-branch-sheet aria-labelledby="branch-sheet-title">
                    <div class="m-choice-label">
                        <h3 id="branch-sheet-title" class="m-muted text-sm font-extrabold">📍 {{ $waysMenu ? __('From which branch?') : __('Prices and what is available follow the branch you choose.') }}</h3>
                        @if ($branches->contains(fn ($b) => $b->lat !== null))
                            <button type="button" class="m-choice-near" data-branch-near>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11 22 2l-9 19-2-8-8-2Z"/></svg>
                                {{ __('Nearest to me') }}
                            </button>
                        @endif
                    </div>
                    @if ($branches->count() >= 5)
                        <input type="search" class="field min-h-11 w-full shrink-0 rounded-2xl text-base" placeholder="{{ __('Search the branches') }}" aria-label="{{ __('Search the branches') }}" data-branch-search>
                    @endif
                    <p class="m-muted hidden shrink-0 text-center text-xs" data-branch-note role="status"></p>
                    <div class="m-choice-branches" role="radiogroup" aria-labelledby="branch-sheet-title" data-branch-list>
                        @foreach ($branches as $b)
                            <a href="?branch={{ $b->slug }}" @class(['m-branch-row', 'is-on' => $b->id === $branch->id]) data-branch-row="{{ $b->id }}" data-branch-pick="{{ $b->slug }}" role="radio" aria-checked="{{ $b->id === $branch->id ? 'true' : 'false' }}">
                                <span class="m-branch-dot" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1"><b class="block truncate">{{ tr($b->name, $b->name_en) }}</b>@if ($b->address)<span class="m-muted block truncate text-xs" dir="auto">{{ $b->address }}</span>@endif</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($waysMenu)
                <div @class(['m-way-grid', 'm-way-grid--3' => $wayCount >= 3]) style="--ways: {{ $wayCount }}" role="group" aria-labelledby="order-choice-title" data-way-pick>
                    @foreach ($sheetWays as $way)
                        @php($wayHere = in_array($way, $ways, true))
                        <button type="button" class="m-way-card m-choice-in" style="--i: {{ $loop->index + 2 }}" data-way="{{ $way }}" aria-pressed="false" @disabled(! $wayHere) @if (! $wayHere) aria-describedby="way-missing" @endif>
                            <span class="m-way-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $wayCards[$way][2] !!}</svg></span>
                            <span class="m-way-name">{{ $wayCards[$way][0] }}</span>
                            <span class="m-way-sub">{{ $wayCards[$way][1] }}</span>
                        </button>
                    @endforeach
                </div>
                {{-- the chosen branch lacks a way another branch has: say so, with a one-tap switch (resources/js/menu.js) --}}
                <div id="way-missing" class="hidden w-full shrink-0 rounded-2xl bg-amber-50 px-3 py-2 text-start text-sm font-semibold text-amber-900 ring-1 ring-amber-200" role="status" data-way-missing>
                    <span data-way-missing-text></span>
                    <button type="button" class="m-primary-text mt-1 block min-h-9 font-extrabold underline underline-offset-2" data-way-missing-switch></button>
                </div>
                <p class="m-choice-foot m-muted">{{ __('You can change it later from your cart.') }}</p>
            @endif
        </div>
    </div>
@endif
