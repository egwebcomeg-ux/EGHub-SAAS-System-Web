{{-- A phone drawn inside a workflow scene (180 × 352 by default); position it with classes. --}}
<div {{ $attributes->merge(['class' => 'absolute h-[352px] w-[180px] rounded-[30px] bg-[#0E1630] p-[7px] shadow-[0_24px_40px_-24px_rgba(19, 29, 61, .7)]']) }}>
    <div class="relative h-full overflow-hidden rounded-[23px] bg-[#fbf7f1] text-navy-900">{{ $slot }}</div>
</div>
