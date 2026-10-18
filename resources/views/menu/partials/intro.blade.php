{{-- Intro splash (setting menu_intro) and the "how will you order?" screen; shown by the classes intro-head sets. --}}
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
                <img src="/storage/{{ $settings['logo'] }}" alt="" class="m-intro-logo" fetchpriority="low">
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
@if ($choice)
    <div class="m-choice" data-order-choice role="dialog" aria-modal="true" aria-labelledby="order-choice-title">
        <div class="m-choice-card m-surface">
            @if ($settings['logo'])
                <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" class="size-16 rounded-2xl bg-white object-contain p-1 shadow">
            @else
                <span class="text-xl font-black">{{ $brand }}</span>
            @endif
            <h2 id="order-choice-title" class="text-2xl font-black">{{ __('How will you order?') }}</h2>
            <p class="m-muted -mt-2 text-sm">{{ __('Choose how you want your order to continue.') }}</p>
            <div class="mt-2 grid w-full gap-3">
                @foreach ($ways as $way)
                    <button type="button" @class(['flex min-h-14 w-full items-center justify-center gap-3 rounded-2xl text-lg font-extrabold', 'm-primary' => $loop->first, 'm-surface-2 m-border border' => ! $loop->first])
                        data-way="{{ $way }}"><span aria-hidden="true">{{ $wayButtons[$way][0] }}</span> {{ $wayButtons[$way][1] }}</button>
                @endforeach
            </div>
            <p class="m-muted text-xs">{{ __('You can change it later from your cart.') }}</p>
        </div>
    </div>
@endif
