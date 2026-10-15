@props(['m', 'note' => null])
{{-- A module in a grid: real screenshot thumbnail, name, one line, link to its page. --}}
<a href="{{ route('features.show', $m['slug']) }}" {{ $attributes->merge(['class' => 'group flex flex-col overflow-hidden rounded-2xl border border-navy-100 bg-white transition hover:border-navy-300 hover:shadow-[0_18px_36px_-26px_rgba(20,31,56,.5)]']) }}>
    <div class="relative aspect-[16/10] overflow-hidden border-b border-navy-100 bg-navy-50">
        <img src="{{ asset('img/site/' . $m['shot'] . '-sm.webp') }}" alt="" width="800" height="{{ ['tv' => 450, 'tablet' => 600][$m['frame']] ?? 504 }}" loading="lazy" decoding="async"
             class="h-full w-full object-cover object-right-top transition duration-500 group-hover:scale-[1.03] ltr:object-left-top">
    </div>
    <div class="flex flex-1 flex-col p-4">
        <div class="flex items-center gap-2">
            <x-landing.icon :name="$m['icon']" class="size-5 shrink-0 text-brand-600" />
            <h3 class="text-base font-extrabold text-navy-900">{{ $m['name'] }}</h3>
        </div>
        <p class="mt-1 flex-1 text-sm leading-relaxed text-navy-500">{{ $m['summary'] }}</p>
        @if ($note)<p class="mt-3 text-xs font-bold text-navy-600">{{ $note }}</p>@endif
    </div>
</a>
