{{-- Tiny drawing of a menu theme for the settings picker. --}}
@php
    $bg = match ($theme) {
        'dark' => 'bg-[#0f1420]', 'cafe' => 'bg-[#f6efe6]', 'bistro' => 'bg-[#141414]', 'fastfood' => 'bg-[#fff8e7]', 'oriental' => 'bg-[#f8f1e3]', 'story' => 'bg-[#f7eedc]',
        default => 'bg-navy-50',
    };
@endphp
<div class="h-24 overflow-hidden rounded-lg p-2 {{ $bg }}">
    @if (in_array($theme, ['grid', 'fastfood'], true))
        <div class="grid grid-cols-2 gap-1.5">
            @for ($i = 0; $i < 4; $i++)
                <div @class(['h-9 bg-white', 'rounded' => $theme === 'grid', 'rounded-xl shadow-sm' => $theme === 'fastfood'])><div @class(['h-5', 'rounded-t bg-brand-200' => $theme === 'grid', 'rounded-t-xl bg-[#ffd23f]' => $theme === 'fastfood'])></div></div>
            @endfor
        </div>
    @elseif ($theme === 'minimal')
        @for ($i = 0; $i < 5; $i++)<div class="mb-2 flex items-end gap-1"><div class="h-1.5 w-10 rounded bg-navy-500"></div><div class="flex-1 border-b border-dotted border-navy-300"></div><div class="h-1.5 w-4 rounded bg-brand-500"></div></div>@endfor
    @elseif ($theme === 'bistro')
        <div class="mb-2 text-center text-[8px] text-[#c9a45c]">◆ ─── ◆</div>
        @for ($i = 0; $i < 4; $i++)<div class="mb-2 flex items-end gap-1"><div class="h-1.5 w-10 rounded bg-[#ece6da]/80"></div><div class="flex-1 border-b border-dotted border-[#c9a45c]/50"></div><div class="h-1.5 w-4 rounded bg-[#c9a45c]"></div></div>@endfor
    @elseif ($theme === 'story')
        {{-- the full-screen welcome with a zigzag edge, then a printed-menu list --}}
        <div class="-mx-2 -mt-2 mb-2 grid h-11 place-items-center bg-navy-800" style="clip-path: polygon(0 0, 100% 0, 100% 82%, 95% 100%, 90% 82%, 85% 100%, 80% 82%, 75% 100%, 70% 82%, 65% 100%, 60% 82%, 55% 100%, 50% 82%, 45% 100%, 40% 82%, 35% 100%, 30% 82%, 25% 100%, 20% 82%, 15% 100%, 10% 82%, 5% 100%, 0 82%)">
            <div class="h-2 w-14 rounded bg-white/90"></div>
        </div>
        @for ($i = 0; $i < 3; $i++)<div class="mb-2 flex items-end gap-1"><div class="h-1.5 w-10 rounded" style="background: #2a1a10"></div><div class="flex-1 border-b border-dotted" style="border-color: #b5452b80"></div><div class="h-1.5 w-4 rounded" style="background: #b5452b"></div></div>@endfor
    @elseif ($theme === 'cafe')
        @for ($i = 0; $i < 3; $i++)
            <div class="mb-1.5 flex items-center gap-1.5 rounded-xl bg-[#fffaf4] p-1">
                <div class="size-5 rounded-full bg-[#c8a382]"></div>
                <div class="flex-1 space-y-1"><div class="h-1.5 w-12 rounded bg-[#4a3426]"></div><div class="h-1.5 w-6 rounded-full bg-brand-400"></div></div>
            </div>
        @endfor
    @else
        @php
            $dark = $theme === 'dark';
            $card = match ($theme) { 'dark' => 'bg-[#1a2130]', 'oriental' => 'bg-[#fffdf8] ring-1 ring-[#e3cfa8]', default => 'bg-white' };
        @endphp
        @for ($i = 0; $i < 3; $i++)
            <div class="mb-1.5 flex items-center gap-1.5 rounded p-1 {{ $card }}">
                <div class="flex-1 space-y-1"><div class="h-1.5 w-12 rounded {{ $dark ? 'bg-white/70' : ($theme === 'oriental' ? 'bg-[#6b1d1d]' : 'bg-navy-500') }}"></div><div class="h-1.5 w-6 rounded bg-brand-500"></div></div>
                <div class="size-5 rounded bg-brand-200"></div>
            </div>
        @endfor
    @endif
</div>
