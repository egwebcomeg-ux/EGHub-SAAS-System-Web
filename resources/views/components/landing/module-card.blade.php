@props(['m', 'note' => null, 'compact' => false])
{{-- A module in a grid: real screenshot thumbnail, name, one line, link to its page. $compact drops the thumbnail on phones (icon, name and line only). --}}
<a href="{{ route('features.show', $m['slug']) }}" {{ $attributes->merge(['class' => 'lp-lift group flex flex-col overflow-hidden rounded-[24px] border border-navy-100 bg-white']) }}>
    <div @class(['relative aspect-[16/10] overflow-hidden border-b border-navy-100 bg-navy-50', 'max-sm:hidden' => $compact])>
        <img src="{{ versioned_asset('img/site/' . $m['shot'] . '-sm.webp') }}" alt="" width="800" height="{{ ['tv' => 450, 'tablet' => 600][$m['frame']] ?? 504 }}" loading="lazy" decoding="async"
             class="h-full w-full object-cover object-right-top transition duration-500 group-hover:scale-[1.03] ltr:object-left-top">
    </div>
    <div @class(['flex flex-1 flex-col p-4 sm:p-5', 'max-sm:flex-row max-sm:items-start max-sm:gap-3' => $compact])>
        @if ($compact)
            <span class="lp-idea-icon size-11! sm:hidden"><x-landing.icon :name="$m['icon']" class="size-5" /></span>
        @endif
        <div class="flex min-w-0 flex-1 flex-col">
            <div class="flex items-center gap-2">
                <x-landing.icon :name="$m['icon']" @class(['size-5 shrink-0 text-brand-600', 'max-sm:hidden' => $compact]) />
                <h3 class="text-base font-extrabold text-navy-900">{{ $m['name'] }}</h3>
            </div>
            <p class="mt-1 flex-1 text-sm leading-relaxed text-navy-500">{{ $m['summary'] }}</p>
            @if ($note)<p class="mt-3 text-xs font-bold text-navy-600">{{ $note }}</p>@endif
        </div>
    </div>
</a>
