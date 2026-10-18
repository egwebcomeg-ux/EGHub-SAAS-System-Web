{{-- Tiny drawing of a menu item layout (App\Support\MenuLayouts) for the settings picker. --}}
<div class="h-16 overflow-hidden rounded-lg bg-navy-50 p-1.5" aria-hidden="true">
    @if ($layout === 'grid')
        <div class="grid grid-cols-2 gap-1">
            @for ($i = 0; $i < 2; $i++)
                <div class="rounded bg-white"><div class="h-5 rounded-t bg-brand-200"></div><div class="m-1 h-1 w-6 rounded bg-navy-500"></div></div>
            @endfor
        </div>
    @elseif ($layout === 'cards')
        <div class="rounded bg-white"><div class="h-8 rounded-t bg-brand-200"></div><div class="m-1 flex justify-between"><div class="h-1 w-10 rounded bg-navy-500"></div><div class="h-1 w-4 rounded bg-brand-500"></div></div></div>
    @elseif ($layout === 'text')
        @for ($i = 0; $i < 4; $i++)<div class="mb-1.5 flex items-end gap-1"><div class="h-1 w-9 rounded bg-navy-500"></div><div class="flex-1 border-b border-dotted border-navy-300"></div><div class="h-1 w-3 rounded bg-brand-500"></div></div>@endfor
    @elseif ($layout === 'list')
        @for ($i = 0; $i < 3; $i++)
            <div class="mb-1 flex items-center gap-1 rounded bg-white p-0.5">
                <div class="flex-1 space-y-0.5"><div class="h-1 w-10 rounded bg-navy-500"></div><div class="h-1 w-4 rounded bg-brand-500"></div></div>
                <div class="size-3 rounded-sm bg-brand-200"></div>
            </div>
        @endfor
    @else
        <div class="grid h-full place-items-center text-center text-[10px] font-bold leading-tight text-navy-500">{{ __('Like the design') }}</div>
    @endif
</div>
