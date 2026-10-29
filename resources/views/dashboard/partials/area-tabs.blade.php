{{--
    The tab bar of an area whose pages are tabs (App\Support\AreaTabs): $area = corporate|reports, $current = the open
    tab's key, $tabCounts = optional [key => number] shown on a tab. Nothing when only one tab is open to this user.
--}}
@php
    $areaTabs = \App\Support\AreaTabs::for($area, auth()->user());
    $areaCounts = $tabCounts ?? [];
@endphp
@if (count($areaTabs) > 1)
    @include('dashboard.partials.tabs', [
        'label' => $area === 'corporate' ? __('Corporate') : __('Reports'), 'current' => $current, 'navAttr' => 'data-area-tabs='.$area,
        'tabs' => array_map(fn (array $tab) => [$tab['key'], $tab['label'], $tab['url'], ($areaCounts[$tab['key']] ?? 0) > 0 ? $areaCounts[$tab['key']] : null], $areaTabs),
    ])
@endif
