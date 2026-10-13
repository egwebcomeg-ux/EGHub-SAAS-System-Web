{{-- Tiny drawing of a menu theme for the settings picker. --}}
@php($dark = $theme === 'dark')
<div class="h-24 overflow-hidden rounded-lg p-2 {{ $dark ? 'bg-[#0f1420]' : 'bg-navy-50' }}">
    @if ($theme === 'grid')
        <div class="grid grid-cols-2 gap-1.5">
            @for ($i = 0; $i < 4; $i++)<div class="h-9 rounded bg-white"><div class="h-5 rounded-t bg-brand-200"></div></div>@endfor
        </div>
    @elseif ($theme === 'minimal')
        @for ($i = 0; $i < 5; $i++)<div class="mb-2 flex items-end gap-1"><div class="h-1.5 w-10 rounded bg-navy-500"></div><div class="flex-1 border-b border-dotted border-navy-300"></div><div class="h-1.5 w-4 rounded bg-brand-500"></div></div>@endfor
    @else
        @for ($i = 0; $i < 3; $i++)
            <div class="mb-1.5 flex items-center gap-1.5 rounded p-1 {{ $dark ? 'bg-[#1a2130]' : 'bg-white' }}">
                <div class="flex-1 space-y-1"><div class="h-1.5 w-12 rounded {{ $dark ? 'bg-white/70' : 'bg-navy-500' }}"></div><div class="h-1.5 w-6 rounded bg-brand-500"></div></div>
                <div class="size-5 rounded bg-brand-200"></div>
            </div>
        @endfor
    @endif
</div>
