{{--
    Page tabs (pills that scroll sideways on phones): $tabs = list of [key, label, url, count?], $current = the open key,
    $label = what the tabs are about (for screen readers). Extra attributes per link via $attr (e.g. data-settings-tab).
--}}
<nav class="mb-4 -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0" aria-label="{{ $label }}" @isset($navAttr) {{ $navAttr }} @endisset>
    @foreach ($tabs as $tab)
        @php
            [$key, $text, $url] = $tab;
            $count = $tab[3] ?? null;
            $on = $current === $key;
        @endphp
        <a href="{{ $url }}" data-tab="{{ $key }}" @isset($attr) {{ $attr }}="{{ $key }}" @endisset @if ($on) aria-current="page" @endif
           @class(['tab-pill', 'bg-navy-900 text-white' => $on, 'bg-white text-navy-700 ring-1 ring-navy-100 hover:ring-navy-300' => ! $on])>
            {{ $text }}@if ($count !== null)<span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-white/20' => $on, 'bg-navy-50 text-navy-500' => ! $on])>{{ $count }}</span>@endif
        </a>
    @endforeach
</nav>
