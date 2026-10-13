@props(['icon' => 'cup', 'logo' => null])
{{-- Stamp glyph on the loyalty card. "logo" uses the restaurant logo, the rest are simple filled shapes. --}}
@if ($icon === 'logo' && $logo)
    <img src="/storage/{{ $logo }}" alt="" {{ $attributes->merge(['class' => 'object-contain']) }}>
@else
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {{ $attributes }}>
        @switch($icon)
            @case('bean')
                <path d="M15.6 3.2c-3.6-1.3-8.2 1.4-10 5.8-1.9 4.5-.7 9.2 2.7 10.6 3.6 1.3 8.2-1.4 10-5.8 1.9-4.5.7-9.2-2.7-10.6zM8.4 18c1-2.6.5-4.5-.4-6.3-.8-1.7-1.3-3.3-.5-5.6 2.2-1.9 5-2.6 7.2-1.8-1 2.6-.5 4.5.4 6.3.8 1.7 1.3 3.3.5 5.6-2.2 1.9-5 2.6-7.2 1.8z"/>
                @break
            @case('star')
                <path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/>
                @break
            @case('heart')
                <path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.7 4.5c2.1 0 3.6 1.2 4.4 2.5.8-1.3 2.3-2.5 4.4-2.5 3.7 0 5.8 3.9 4.3 7.3C19.5 16.4 12 21 12 21z"/>
                @break
            @case('burger')
                <path d="M3 10c0-3.9 4-6.5 9-6.5s9 2.6 9 6.5H3zm-.5 2h19a1.5 1.5 0 0 1 0 3h-19a1.5 1.5 0 0 1 0-3zM4 17h16v1a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-1z"/>
                @break
            @case('pizza')
                <path d="M12 2C7.5 2 3.6 3.6 1.5 5.4L12 22 22.5 5.4C20.4 3.6 16.5 2 12 2zm-3 6.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/>
                @break
            @default
                {{-- cup --}}
                <path d="M3 8h13v6a6 6 0 0 1-6 6H9a6 6 0 0 1-6-6V8zm13 1h1.5a3.5 3.5 0 0 1 0 7H16v-2h1.5a1.5 1.5 0 0 0 0-3H16V9zM7 2.5c.8.8.8 1.7 0 2.5M10.5 2.5c.8.8.8 1.7 0 2.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        @endswitch
    </svg>
@endif
