{{--
    One kind of TV or device (App\Support\ScreenDevices) and how to set it up: the recommended way with its numbered
    steps, the alternative, keeping it on all day, after a power cut, and its limits. Used by «اربط شاشة», a screen's
    help page in the dashboard and the public page /supported-screens.
    $device: a ScreenDevices entry · $showTitle: false when the page already names it (kept for screen readers) · $expanded: the alternative and the extra sections start open · $heading: h2 | h3
--}}
@php
    use App\Support\ScreenDevices;
    $expanded = $expanded ?? false;
    $heading = $heading ?? 'h3';
    $powerTone = ['yes' => 'bg-emerald-50 text-emerald-800', 'partial' => 'bg-amber-50 text-amber-800', 'no' => 'bg-red-50 text-red-700'][$device['power']] ?? 'bg-navy-50 text-navy-700';
@endphp
<div class="space-y-4" data-device-guide="{{ $device['key'] }}">
    <div @class(['flex flex-wrap items-center gap-2', 'sr-only' => ! ($showTitle ?? true)])>
        <{{ $heading }} class="text-lg font-extrabold text-navy-900"><span dir="auto">{{ $device['name'] }}</span> <span class="text-base font-semibold text-navy-500">· {{ $device['label'] }}</span></{{ $heading }}>
        @unless ($device['supported'])
            <span class="badge bg-red-50 text-red-700">{{ __('Not supported directly') }}</span>
        @endunless
    </div>

    <div class="rounded-2xl border-2 border-brand-500 bg-brand-50/60 p-4" data-method="{{ $device['method'] }}">
        <p class="text-sm font-extrabold text-brand-700">{{ __('Recommended') }}: {{ ScreenDevices::methodLabel($device['method']) }}</p>
        <ol class="mt-3 space-y-2.5">
            @foreach ($device['steps'] as $step)
                <li class="flex gap-3 text-sm leading-relaxed text-navy-800">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-600 text-xs font-extrabold text-white">{{ $loop->iteration }}</span>
                    <span class="min-w-0 flex-1 pt-0.5">{!! ScreenDevices::format($step) !!}</span>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($device['alt'])
        <details class="rounded-2xl border border-navy-100 p-4" @if ($expanded) open @endif>
            <summary class="cursor-pointer text-sm font-bold text-navy-800">{{ __('Another way') }}: {{ ScreenDevices::methodLabel($device['alt']) }}</summary>
            <ol class="mt-3 list-decimal space-y-1.5 ps-5 text-sm leading-relaxed text-navy-700">
                @foreach ($device['alt_steps'] as $step)
                    <li>{!! ScreenDevices::format($step) !!}</li>
                @endforeach
            </ol>
        </details>
    @endif

    <div class="grid gap-3 md:grid-cols-2">
        @if ($device['awake'])
            <section class="rounded-2xl border border-navy-100 p-4" data-awake>
                <h4 class="text-sm font-extrabold text-navy-900">{{ __('Keep it on all day') }}</h4>
                <ul class="mt-2 list-disc space-y-1.5 ps-5 text-sm leading-relaxed text-navy-700">
                    @foreach ($device['awake'] as $line)
                        <li>{!! ScreenDevices::format($line) !!}</li>
                    @endforeach
                </ul>
            </section>
        @endif
        <section class="rounded-2xl border border-navy-100 p-4" data-power="{{ $device['power'] }}">
            <h4 class="flex flex-wrap items-center gap-2 text-sm font-extrabold text-navy-900">{{ __('After a power cut') }} <span class="badge {{ $powerTone }}">{{ ScreenDevices::powerLabel($device['power']) }}</span></h4>
            <p class="mt-2 text-sm leading-relaxed text-navy-700">{!! ScreenDevices::format($device['power_text']) !!}</p>
        </section>
    </div>

    @if ($device['notes'])
        <section class="rounded-2xl bg-navy-50 p-4">
            <h4 class="text-sm font-extrabold text-navy-900">{{ __('Good to know') }}</h4>
            <ul class="mt-2 list-disc space-y-1.5 ps-5 text-sm leading-relaxed text-navy-700">
                @foreach ($device['notes'] as $line)
                    <li>{!! ScreenDevices::format($line) !!}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
