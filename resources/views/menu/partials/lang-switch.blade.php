{{-- Language of the public menu: a toggle for Arabic/English, a small menu when extra languages are on. --}}
@php
    $langs = \App\Support\MenuLocales::available();
    $currentLang = \App\Support\MenuLocales::pageLocale();
    $short = fn ($code) => $code === 'ar' ? 'ع' : strtoupper($code);
@endphp
@if (count($langs) === 2)
    @php
        $other = $langs[0] === $currentLang ? $langs[1] : $langs[0];
    @endphp
    <a href="?lang={{ $other }}" hreflang="{{ $other }}" class="shrink-0 {{ $class ?? '' }} rounded-full border border-white/30 px-3 py-1.5 text-sm font-semibold">{{ $short($other) }}</a>
@elseif (count($langs) > 2)
    <details class="relative shrink-0 {{ $class ?? '' }}" data-lang-menu>
        <summary class="flex cursor-pointer list-none items-center gap-1 rounded-full border border-white/30 px-3 py-1.5 text-sm font-semibold" aria-label="{{ __('Language') }}">
            <span aria-hidden="true">🌐</span> {{ $short($currentLang) }}
        </summary>
        <ul class="absolute end-0 z-40 mt-2 min-w-40 overflow-hidden rounded-2xl bg-white py-1 text-start text-navy-900 shadow-xl ring-1 ring-black/10">
            @foreach ($langs as $code)
                <li>
                    <a href="?lang={{ $code }}" hreflang="{{ $code }}" lang="{{ $code }}" dir="{{ \App\Support\MenuLocales::dir($code) }}"
                       @class(['flex items-center justify-between gap-3 px-4 py-2.5 text-sm hover:bg-navy-50', 'font-extrabold' => $code === $currentLang])
                       @if ($code === $currentLang) aria-current="true" @endif>
                        <span>{{ \App\Support\MenuLocales::native($code) }}</span>
                        <span class="text-xs text-navy-500">{{ $short($code) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
@endif
