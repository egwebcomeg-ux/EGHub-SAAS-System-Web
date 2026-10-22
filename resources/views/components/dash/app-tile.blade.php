@props(['app', 'badge' => 0, 'hue' => 'bg-app-management'])
{{--
    One app on the home screen (dashboard/home.blade.php): a coloured square with the icon, the name under it, and a
    small counter when there is something waiting. $app comes from App\Support\DashboardApps::home(); a locked tile is a
    picked module the plan does not include, greyed with a lock and pointing to billing. The whole tile is the link.
--}}
<a href="{{ $app['url'] }}"@if ($app['module']) data-home-module="{{ $app['module'] }}"@endif data-app-tile
   @if ($app['route']) data-app="{{ $app['route'] }}" @endif @if ($app['locked']) data-app-locked @endif
   data-search="{{ mb_strtolower($app['label']) }}" title="{{ $app['locked'] ? __('Upgrade') : $app['label'] }}"
   @class(['app-tile', 'app-tile-locked' => $app['locked']])>
    <span class="relative">
        <span @class(['app-tile-icon', $hue => ! $app['locked'], 'bg-navy-100 text-navy-400' => $app['locked']])>
            @if (! empty($app['landingIcon']))
                <x-landing.icon :name="$app['landingIcon']" class="size-7" />
            @else
                <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $app['icon'] }}"/></svg>
            @endif
        </span>
        @if ($badge > 0)
            <span class="app-tile-badge" data-app-badge="{{ $app['route'] }}">{{ $badge > 99 ? '99+' : $badge }}</span>
        @endif
        @if ($app['locked'])
            <span class="app-tile-lock" aria-hidden="true">
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </span>
        @endif
    </span>
    <span class="app-tile-label">{{ $app['label'] }}</span>
    @if ($app['locked'])<span class="text-[11px] font-bold text-brand-700">{{ __('Upgrade') }}</span>@endif
</a>
