{{--
    Wallet-style loyalty card (the design the client sent): colored front with the logo, program title, stamp count
    and the guest's QR; a darker band with the stamp grid where reward positions show a gift.
    Expects: $program, $milestones, $stamps (int), $settings, optional $qr (svg) and $name.
--}}
@php
    $design = $program->card_design ?? [];
    $bg = is_hex_color($design['bg'] ?? null) ? $design['bg'] : '#B5451B';
    $stampBg = is_hex_color($design['stamp_bg'] ?? null) ? $design['stamp_bg'] : '#7A1F2B';
    $icon = $design['icon'] ?? 'cup';
    $points = $program->type === 'points';
    $size = (int) $program->stamps_required;
    $rewardAt = collect($milestones)->pluck('at')->all();
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
@endphp
<div class="mx-auto w-full max-w-sm overflow-hidden rounded-[1.75rem] text-white shadow-2xl shadow-black/20" style="background: {{ $bg }}">
    <div class="p-6 pb-5">
        <div class="flex items-center gap-3">
            @if ($settings['logo'])
                <img src="/storage/{{ $settings['logo'] }}" alt="" class="size-10 rounded-full bg-white object-contain p-0.5">
            @else
                <span class="flex size-10 items-center justify-center rounded-full bg-white/20 text-lg font-extrabold">{{ mb_substr($brand, 0, 1) }}</span>
            @endif
            <span class="text-lg font-bold tracking-wide">{{ $brand }}</span>
        </div>
        <div class="mt-4 text-3xl font-extrabold leading-tight">{{ tr($program->title, $program->title_en) }}</div>
        <div class="mt-3 text-sm text-white/80">{{ $points ? __('Points') : __('Stamps') }}</div>
        <div class="text-2xl font-extrabold">{{ $stamps }}@unless ($points)<span class="text-base font-semibold text-white/70"> / {{ $size }}</span>@endunless</div>
        @isset($name)<div class="mt-1 text-sm text-white/80">{{ $name }}</div>@endisset
        @isset($qr)
            <div class="mx-auto mt-5 w-44 rounded-2xl bg-white p-3 [&_svg]:h-auto [&_svg]:w-full">{!! $qr !!}</div>
        @endisset
    </div>

    @unless ($points)
        <div class="grid grid-cols-5 gap-2.5 p-5" style="background: {{ $stampBg }}">
            @for ($i = 1; $i <= $size; $i++)
                @php($filled = $i <= $stamps)
                @php($gift = in_array($i, $rewardAt, true))
                <div @class(['flex aspect-square items-center justify-center rounded-full', 'bg-white' => $filled, 'bg-white/80' => !$filled])>
                    @if ($gift && !$filled)
                        <svg class="size-1/2 text-navy-500/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-label="{{ __('Reward') }}"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>
                    @else
                        <x-stamp-icon :icon="$icon" :logo="$settings['logo']" class="size-3/5" style="color: {{ $filled ? $bg : '#c9c9c9' }}" />
                    @endif
                </div>
            @endfor
        </div>
    @endunless
</div>
