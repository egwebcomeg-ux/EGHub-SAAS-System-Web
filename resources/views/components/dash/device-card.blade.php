@props(['type', 'label', 'name', 'url', 'qr', 'meta' => null, 'inactive' => false, 'open' => null])
{{--
    One screen or device on the "Screens & devices" page: its QR to open it on the device, its link with "Copy link", one
    row of actions (the $actions slot first when the card has its own main action, then "open"), then the rest (slot).
--}}
<section {{ $attributes->class(['card flex min-w-0 flex-col gap-3', 'opacity-70' => $inactive]) }} data-device="{{ $type }}">
    <div class="flex items-start gap-4">
        <div class="size-24 shrink-0 rounded-xl bg-white p-1 ring-1 ring-navy-100 sm:size-28 [&_svg]:h-full [&_svg]:w-full" title="{{ __('Scan it with the device camera to open the link') }}">{!! $qr !!}</div>
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="badge bg-navy-50 text-navy-600">{{ $label }}</span>
                @if ($inactive)<span class="badge bg-amber-100 text-amber-800">{{ __('Stopped') }}</span>@endif
            </div>
            <h3 class="truncate text-base font-bold sm:text-lg">{{ $name }}</h3>
            @if ($meta)<p class="text-xs text-navy-500">{{ $meta }}</p>@endif
            <div class="flex items-center gap-1 rounded-lg bg-navy-50 ps-2 text-xs text-navy-600">
                <span class="min-w-0 flex-1 truncate" dir="ltr" title="{{ $url }}">{{ $url }}</span>
                <button type="button" class="min-h-9 shrink-0 px-2 font-semibold text-brand-700 hover:underline" data-copy="{{ $url }}">{{ __('Copy link') }}</button>
            </div>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        {{ $actions ?? '' }}
        <a href="{{ $url }}" target="_blank" rel="noopener" @class(['min-h-9 px-3', 'btn-ghost' => isset($actions), 'btn-primary' => ! isset($actions)])>{{ $open ?? __('Open') }}</a>
    </div>
    {{ $slot }}
</section>
