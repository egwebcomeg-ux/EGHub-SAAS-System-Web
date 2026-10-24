{{--
    A moving strip of logos (CSS only: pauses on hover/focus, static wrapped rows under prefers-reduced-motion).
    $logos: list of [name, logo (src), url (may be empty: no link), width?, height?, rel?]; $marker: prefix of the data-*
    attributes on the copies (data-{marker}-copy / data-{marker}-repeat). One copy of the loop must be wider than the
    strip (8 boxes cover 1280px), so a short list is repeated in it; the repeats and the second copy are hidden from
    screen readers and the keyboard.
--}}
@php
    $rounds = (int) ceil(8 / count($logos));
@endphp
<div class="lp-marquee mx-auto mt-6 max-w-7xl" style="--lp-marquee-duration: {{ count($logos) * $rounds * 4 }}s">
    <div class="lp-marquee-track">
        @foreach ([false, true] as $copy)
            <ul class="lp-marquee-list" @if ($copy) aria-hidden="true" data-{{ $marker }}-copy @endif>
                @foreach (array_merge(...array_fill(0, $rounds, $logos)) as $logo)
                    @php $repeat = $copy || $loop->index >= count($logos); @endphp
                    <li class="lp-marquee-item" @if ($repeat && ! $copy) aria-hidden="true" data-{{ $marker }}-repeat @endif>
                        @if (($logo['url'] ?? '') !== '')
                            <a href="{{ $logo['url'] }}" target="_blank" rel="{{ $logo['rel'] ?? $rel ?? 'noopener' }}" class="lp-logo-box" @if ($repeat) tabindex="-1" @endif>
                                <img src="{{ $logo['logo'] }}" alt="{{ $logo['name'] }}" width="{{ $logo['width'] ?? 160 }}" height="{{ $logo['height'] ?? 64 }}" loading="lazy" decoding="async">
                            </a>
                        @else
                            <span class="lp-logo-box">
                                <img src="{{ $logo['logo'] }}" alt="{{ $logo['name'] }}" width="{{ $logo['width'] ?? 160 }}" height="{{ $logo['height'] ?? 64 }}" loading="lazy" decoding="async">
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    </div>
</div>
