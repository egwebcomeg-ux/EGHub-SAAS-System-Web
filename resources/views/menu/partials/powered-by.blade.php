{{--
    The platform marks at the foot of every guest page (menu, about, loyalty card, pager): "Powered by EgHub" unless
    the plan removes it (hide_badge), and the "by EgWeb" capsule while the restaurant does not pay yet (trial or Free),
    light or dark to suit the template. Always in the flow at the bottom, never over the content.
--}}
@php
    $rights = \App\Services\Entitlements::current();
    $platformBadge = ! $rights->allows('hide_badge');
    $egwebBadge = $rights->showsEgWebBadge();
    $menuSettings = \App\Services\Settings::all();
    $darkTheme = \App\Support\MenuThemes::tokens(\App\Support\MenuThemes::current($menuSettings), $menuSettings)['dark'];
@endphp
@if ($platformBadge || $egwebBadge)
    <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-xs {{ $class ?? '' }}" data-powered-by>
        @if ($platformBadge)
            <a href="{{ platform_url(null) }}" class="inline-flex items-center gap-1 text-xs">{{ __('Powered by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
        @endif
        @if ($egwebBadge)
            <x-egweb-badge :tone="$darkTheme ? 'dark' : 'light'" class="!h-8" data-guest-egweb-badge />
        @endif
    </div>
@endif
