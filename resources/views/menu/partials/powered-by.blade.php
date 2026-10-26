{{--
    The platform marks at the foot of every guest page (menu, about, loyalty card, pager): "Powered by EgHub" unless
    the plan removes it (hide_badge), or a "by EgHub" capsule in its place while the restaurant does not pay yet (trial or
    Free), light or dark to suit the template. EgWeb shows on the EgHub site only, never on a restaurant's pages.
    A restaurant whose plan hides the mark still shows it when the platform keeps it on (setting show_platform_badge,
    set by the EgHub team for partners such as Filetto).
    Always in the flow at the bottom, never over the content.
--}}
@php
    $rights = \App\Services\Entitlements::current();
    $menuSettings = \App\Services\Settings::all();
    $platformBadge = ! $rights->allows('hide_badge') || ($menuSettings['show_platform_badge'] ?? '0') === '1';
    $brandCapsule = $rights->showsBrandCapsule();
    $platformBadge = $platformBadge && ! $brandCapsule;
    $darkTheme = \App\Support\MenuThemes::tokens(\App\Support\MenuThemes::current($menuSettings), $menuSettings)['dark'];
@endphp
@if ($platformBadge || $brandCapsule)
    <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-xs {{ $class ?? '' }}" data-powered-by>
        @if ($platformBadge)
            <a href="{{ platform_url(null) }}" class="inline-flex items-center gap-1 text-xs">{{ __('Powered by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
        @endif
        @if ($brandCapsule)
            <a href="{{ platform_url(null) }}" data-guest-brand-badge
               @class(['inline-flex h-8 items-center gap-1.5 rounded-full border ps-3.5 pe-3 text-xs',
                   'border-white/15 bg-white/5 text-white/70' => $darkTheme, 'border-navy-200 bg-white text-navy-600' => ! $darkTheme])>
                {{ __('by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" />
            </a>
        @endif
    </div>
@endif
