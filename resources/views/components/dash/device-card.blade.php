@props(['type', 'label', 'name', 'url', 'qr', 'meta' => null, 'inactive' => false, 'open' => null])
{{-- One screen or device on the "Screens & devices" page: its QR to open it on the device, its link, then its own actions (slot). --}}
<section {{ $attributes->class(['card flex flex-col gap-3', 'opacity-70' => $inactive]) }} data-device="{{ $type }}">
    <div class="flex items-start gap-4">
        <div class="size-24 shrink-0 rounded-xl bg-white p-1 ring-1 ring-navy-100 sm:size-28 [&_svg]:h-full [&_svg]:w-full" title="{{ __('Scan it with the device camera to open the link') }}">{!! $qr !!}</div>
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="badge bg-navy-50 text-navy-600">{{ $label }}</span>
                @if ($inactive)<span class="badge bg-amber-100 text-amber-800">{{ __('Stopped') }}</span>@endif
            </div>
            <h3 class="truncate text-base font-bold sm:text-lg">{{ $name }}</h3>
            @if ($meta)<p class="text-xs text-navy-500">{{ $meta }}</p>@endif
            <div class="rounded-lg bg-navy-50 px-2 py-1 text-xs break-all text-navy-600" dir="ltr">{{ $url }}</div>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-primary min-h-9 px-3">{{ $open ?? __('Open') }}</a>
        <button type="button" class="btn-ghost min-h-9 px-3" data-copy="{{ $url }}">{{ __('Copy link') }}</button>
    </div>
    {{ $slot }}
</section>
