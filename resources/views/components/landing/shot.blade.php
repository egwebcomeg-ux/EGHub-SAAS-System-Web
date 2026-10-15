@props(['name', 'frame' => 'browser', 'url' => '', 'alt' => '', 'eager' => false, 'sizes' => null, 'crop' => false])
{{--
    A real product screenshot from public/img/site in a device frame (browser, phone, tv or a 4:3 tablet). Every shot exists twice: name.webp (2x) and
    name-sm.webp (1x); width/height are set so nothing shifts while it loads.
--}}
@php
    $mobile = $frame === 'phone';
    [$w, $h] = match ($frame) {
        'phone' => [780, 1688],
        'tv' => [1600, 900],
        'tablet' => [1600, 1200],
        default => [1600, 1007],
    };
    $small = asset("img/site/{$name}-sm.webp");
    $big = asset("img/site/{$name}.webp");
    $srcset = $small . ' ' . ($w / 2) . 'w, ' . $big . ' ' . $w . 'w';
    $sizes ??= $mobile ? '(min-width: 1024px) 300px, 70vw' : '(min-width: 1024px) 760px, 100vw';
@endphp
@if ($frame === 'phone')
    <div {{ $attributes->merge(['class' => 'lp-phone']) }}>
        <div><img src="{{ $small }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}" width="{{ $w / 2 }}" height="{{ $h / 2 }}" alt="{{ $alt }}" @if (!$eager) loading="lazy" @endif decoding="async"></div>
    </div>
@elseif ($frame === 'tv' || $frame === 'tablet')
    <div {{ $attributes->merge(['class' => $frame === 'tv' ? 'lp-tv' : 'lp-tablet']) }}>
        <div><img src="{{ $small }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}" width="{{ $w / 2 }}" height="{{ $h / 2 }}" alt="{{ $alt }}" @if (!$eager) loading="lazy" @endif decoding="async"></div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'lp-browser']) }}>
        <div class="lp-browser-bar" aria-hidden="true"><i></i><i></i><i></i><span class="lp-browser-url">{{ $url }}</span></div>
        <div @class(['lp-shot-crop' => $crop])><img src="{{ $small }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}" width="{{ $w / 2 }}" height="{{ $h / 2 }}" alt="{{ $alt }}" @if (!$eager) loading="lazy" @endif decoding="async"></div>
    </div>
@endif
