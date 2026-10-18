@props(['title', 'help' => null])
{{--
    Dashboard page header: title, one short help line, and the page actions (primary first, so it sits next to the
    title in RTL). Under 640px the actions drop below the title and wrap. Extra lines go in the default slot.
--}}
<div {{ $attributes->merge(['class' => 'dash-head']) }}>
    <div class="min-w-0">
        <h1 class="page-title">{{ $title }}</h1>
        @if ($help)<p class="dash-help">{{ $help }}</p>@endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="dash-head-actions">{{ $actions }}</div>
    @endisset
</div>
