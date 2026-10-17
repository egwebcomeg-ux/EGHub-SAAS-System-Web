@props(['title' => '', 'dark' => false])
{{-- A dashboard window drawn inside a workflow scene; position and size it with classes. --}}
<div {{ $attributes->merge(['class' => 'absolute overflow-hidden rounded-2xl border shadow-[0_20px_40px_-26px_rgba(20,31,56,.6)] ' . ($dark ? 'border-navy-900 bg-[#061a2e] text-white' : 'border-navy-100 bg-white text-navy-900')]) }}>
    <div @class(['flex h-8 items-center gap-1.5 border-b px-3 text-[11px] font-bold', 'border-white/10 text-white/70' => $dark, 'border-navy-100 bg-navy-50/60 text-navy-500' => !$dark])>
        <i class="size-2 rounded-full bg-navy-200"></i><i class="size-2 rounded-full bg-navy-200"></i>
        <span class="ms-2 truncate">{{ $title }}</span>
    </div>
    <div class="relative h-[calc(100%-2rem)]">{{ $slot }}</div>
</div>
