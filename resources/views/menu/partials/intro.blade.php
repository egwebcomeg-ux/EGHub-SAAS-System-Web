{{-- Intro splash (setting menu_intro) and the first-open sheet (order type + branch); shown by the classes intro-head sets. --}}
@php
    // the order types as big cards: an icon, the name and one short line (lucide-style strokes, currentColor)
    $wayCards = [
        'dine' => [__('In the place / the hall'), __('Order to your table'),
            '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>'],
        'takeaway' => [__('Takeaway'), __('Ready for you to pick up'),
            '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
        'delivery' => [__('Delivery order'), __('We bring it to your door'),
            '<circle cx="5.5" cy="17.5" r="3"/><circle cx="18.5" cy="17.5" r="3"/><path d="M8.5 17.5h6.5l-2-7H9"/><path d="M15 6h2.5l2.5 8.5"/><path d="M3 11h5"/>'],
    ];
    $introTagline = trim(tr($settings['tagline'], $settings['tagline_en']));
    $introLight = ($introBackground ?? 'brand') === 'light';
@endphp
@if ($intro !== 'off')
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
        The one first-open sheet, one step: with 2+ branches (no table QR) the branch on top — a compact list, a search
        from 5 branches, "nearest to me" asks for the location only on a tap, nearest first when it is already allowed —
        then the order types as big cards: tapping one continues at once (another branch reloads its menu). With one way
        to order, tapping a branch continues. It opens by itself once (setting order_type_prompt, or a first visit with no
        branch chosen, intro-head) and again from the chips in the header. The menu shows blurred behind it; the popup
        banner waits until it is closed.
    --}}
    <div class="m-choice" data-order-choice data-first-sheet role="dialog" aria-modal="true" aria-labelledby="order-choice-title">
        <div @class(['m-choice-card m-surface relative', 'm-choice-card--closable' => ! $waysMenu])>
            <div class="m-choice-hero" @if ($settings['cover']) style="background-image: url('/storage/{{ $settings['cover'] }}')" @endif aria-hidden="true"></div>
            <button type="button" class="m-choice-close absolute end-3 top-3 z-10 grid size-10 place-items-center rounded-full text-2xl leading-none" data-choice-close aria-label="{{ __('Close') }}">×</button>
            <div class="m-choice-brand m-choice-in">
                @if ($settings['logo'])
                    <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" @class(['m-choice-logo', 'm-choice-logo--tile' => $logoTile ?? true])>
                @else
                    <span class="m-choice-logo m-choice-logo--name m-primary">{{ mb_substr($brand, 0, 1) }}</span>
                @endif
                <span class="m-choice-name">{{ $brand }}</span>
            </div>
            <h2 id="order-choice-title" class="m-choice-in text-2xl font-black" style="--i: 1">{{ $waysMenu ? __('How will you order?') : __('Choose the branch') }}</h2>
            @if ($branchPicker)
                {{-- the branch part: rows rebuilt by resources/js/menu.js (open now, distance, map); links work without it --}}
                <section class="m-choice-in w-full text-start" style="--i: 2" data-branch-sheet @if ($waysMenu) aria-labelledby="branch-sheet-title" @endif>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 id="branch-sheet-title" class="m-muted text-sm font-extrabold">📍 {{ $waysMenu ? __('From which branch?') : __('Prices and what is available follow the branch you choose.') }}</h3>
                    </div>
                    @if ($branches->contains(fn ($b) => $b->lat !== null))
                        <button type="button" class="m-choice-near mb-2 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl text-base font-extrabold" data-branch-near>
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11 22 2l-9 19-2-8-8-2Z"/></svg>
                            {{ __('Nearest branch to me') }}
                        </button>
                    @endif
                    @if ($branches->count() >= 5)
                        <input type="search" class="field mb-2 min-h-11 w-full rounded-2xl text-base" placeholder="{{ __('Search the branches') }}" aria-label="{{ __('Search the branches') }}" data-branch-search>
                    @endif
                    <p class="m-muted mb-2 hidden text-center text-xs" data-branch-note role="status"></p>
                    <div class="m-choice-branches grid gap-1.5" role="radiogroup" aria-label="{{ __('Choose the branch') }}" data-branch-list>
                        @foreach ($branches as $b)
                            <a href="?branch={{ $b->slug }}" @class(['m-branch-row', 'is-on' => $b->id === $branch->id]) data-branch-row="{{ $b->id }}" data-branch-pick="{{ $b->slug }}" role="radio" aria-checked="{{ $b->id === $branch->id ? 'true' : 'false' }}">
                                <span class="m-branch-dot" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1"><b class="block truncate">{{ tr($b->name, $b->name_en) }}</b>@if ($b->address)<span class="m-muted block truncate text-xs">{{ $b->address }}</span>@endif</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($waysMenu)
                <div class="grid w-full gap-2.5" role="group" aria-labelledby="order-choice-title" data-way-pick>
                    @foreach ($ways as $way)
                        <button type="button" class="m-way-card m-choice-in" style="--i: {{ $loop->index + 3 }}" data-way="{{ $way }}" aria-pressed="false">
                            <span class="m-way-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $wayCards[$way][2] !!}</svg></span>
                            <span class="min-w-0 flex-1 text-start">
                                <b class="block text-lg leading-tight">{{ $wayCards[$way][0] }}</b>
                                <span class="m-muted block text-sm">{{ $wayCards[$way][1] }}</span>
                            </span>
                            <span class="m-way-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
                        </button>
                    @endforeach
                </div>
                <p class="m-muted m-choice-in text-xs" style="--i: 7">{{ __('You can change it later from your cart.') }}</p>
            @endif
        </div>
    </div>
@endif
