@extends('layouts.landing')
@section('title', __('Screens that work with EgHub') . ' · EgHub')
@section('share_card', 'supported-screens')
@section('description', __('Samsung, LG, Xiaomi, Sony, TCL, Fire TV, Google TV, Android boxes, computers and tablets: how to put your menu on each one, keep it on all day and what happens after a power cut.'))

@php
    use App\Support\ScreenDevices;
@endphp

@section('page')
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto max-w-3xl px-4 pt-8 pb-10 text-center sm:px-6 lg:pt-12 lg:pb-12">
        <nav class="text-sm font-semibold text-navy-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('features') }}" class="hover:text-navy-900">{{ __('Features') }}</a>
            <span class="mx-1.5 text-navy-300" aria-hidden="true">/</span>
            <a href="{{ route('features.show', 'tv-screens') }}" class="hover:text-navy-900">{{ __('TV menu screens') }}</a>
        </nav>
        <h1 class="mx-auto mt-5 text-[1.9rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.2]" data-reveal>{{ __('Screens that work with EgHub') }}</h1>
        <p class="lp-lead mx-auto mt-4 max-w-2xl" data-reveal style="--d: 70ms">{{ __('Almost any screen in your place can show your menu: a smart TV through its browser, an Android or Google TV through the EgHub Screens app, and any normal TV with a small box on HDMI. Pick yours to see the exact steps.') }}</p>
    </div>
</section>

<section class="py-10 lg:py-14" aria-labelledby="summary-title">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <h2 id="summary-title" class="lp-h2 text-center">{{ __('At a glance') }}</h2>
        <div class="mt-8 overflow-x-auto rounded-3xl border border-navy-100 bg-white">
            <table class="w-full min-w-[640px] text-start text-sm" data-screens-table>
                <thead class="bg-navy-50 text-navy-600">
                    <tr>
                        <th class="px-4 py-3 text-start font-extrabold">{{ __('Screen') }}</th>
                        <th class="px-4 py-3 text-start font-extrabold">{{ __('Recommended way') }}</th>
                        <th class="px-4 py-3 text-start font-extrabold">{{ __('Another way') }}</th>
                        <th class="px-4 py-3 text-start font-extrabold">{{ __('After a power cut') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-navy-100">
                    @foreach ($devices as $key => $device)
                        <tr data-device-row="{{ $key }}">
                            <td class="px-4 py-3"><a href="#{{ $key }}" class="font-extrabold text-navy-900 hover:text-brand-700"><span dir="auto">{{ $device['name'] }}</span></a> <span class="text-navy-500">· {{ $device['label'] }}</span></td>
                            <td class="px-4 py-3 font-semibold text-navy-800">{{ ScreenDevices::methodLabel($device['method']) }}@unless ($device['supported']) <span class="badge bg-red-50 text-red-700">{{ __('Not supported directly') }}</span>@endunless</td>
                            <td class="px-4 py-3 text-navy-600">{{ $device['alt'] ? ScreenDevices::methodLabel($device['alt']) : '—' }}</td>
                            <td class="px-4 py-3 text-navy-600">{{ ScreenDevices::powerLabel($device['power']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="lp-band py-12 lg:py-16" aria-labelledby="shape-title" data-shape-help>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <h2 id="shape-title" class="lp-h2 text-center">{{ __('Landscape or portrait, and any size') }}</h2>
        <p class="lp-lead mx-auto mt-4 max-w-2xl text-center">{{ __('When you pair the screen you choose how it hangs, its size and how far the guests stand. The screen turns the menu by itself and sets the text size to match.') }}</p>
        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-navy-100 bg-white p-5 text-center">
                <div class="grid h-28 place-items-center" aria-hidden="true"><span class="block h-16 w-28 rounded-lg border-[6px] border-navy-800 bg-navy-100"></span></div>
                <h3 class="mt-3 text-lg font-extrabold">{{ __('Landscape') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-navy-600">{{ __('The usual way: a wide menu in columns, good above the counter.') }}</p>
            </div>
            <div class="rounded-3xl border border-navy-100 bg-white p-5 text-center">
                <div class="grid h-28 place-items-center" aria-hidden="true"><span class="block h-28 w-16 rounded-lg border-[6px] border-navy-800 bg-navy-100"></span></div>
                <h3 class="mt-3 text-lg font-extrabold">{{ __('Portrait') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-navy-600">{{ __('Hang any TV on its side and choose which way it is turned: the player turns the menu, no setting on the TV needed. Good at the entrance and beside the door.') }}</p>
            </div>
            <div class="rounded-3xl border border-navy-100 bg-white p-5 text-center">
                <div class="flex h-28 items-end justify-center gap-2" aria-hidden="true">
                    <span class="block h-8 w-12 rounded border-4 border-navy-800 bg-navy-100"></span>
                    <span class="block h-14 w-24 rounded-md border-[5px] border-navy-800 bg-navy-100"></span>
                    <span class="block h-24 w-36 rounded-lg border-[6px] border-navy-800 bg-navy-100"></span>
                </div>
                <h3 class="mt-3 text-lg font-extrabold">{{ __('Any size') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-navy-600">{{ __('From a tablet to 75 inches and more. A small or far screen gets bigger text and fewer items on each page; a big one close to the guests shows more. You can set the text size by hand too.') }}</p>
            </div>
        </div>
    </div>
</section>

@foreach ($groups as $group => $heading)
    <section class="border-t border-navy-100 py-12 lg:py-16" aria-labelledby="group-{{ $group }}">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <h2 id="group-{{ $group }}" class="lp-h2">{{ $heading }}</h2>
            <div class="mt-6 space-y-3">
                @foreach ($devices as $key => $device)
                    @continue($device['group'] !== $group)
                    <details id="{{ $key }}" class="group scroll-mt-24 rounded-3xl border border-navy-100 bg-white p-4 open:bg-[var(--lp-paper)] sm:p-6">
                        <summary class="flex cursor-pointer list-none items-center gap-3">
                            <span class="min-w-0 flex-1">
                                <span class="block text-lg font-extrabold text-navy-900" dir="auto">{{ $device['name'] }}</span>
                                <span class="block text-sm text-navy-500">{{ $device['label'] }} · {{ ScreenDevices::methodLabel($device['method']) }}</span>
                            </span>
                            <span class="grid size-8 shrink-0 place-items-center rounded-full border border-navy-200 text-lg leading-none text-navy-700 group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <div class="mt-5">
                            @include('partials.screen-device-guide', ['device' => $device, 'heading' => 'h3', 'expanded' => true, 'showTitle' => false])
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endforeach

<section class="lp-band py-14 lg:py-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6">
        <h2 class="lp-h2">{{ __('Not sure what your screen is?') }}</h2>
        <ul class="mx-auto mt-6 max-w-2xl space-y-2 text-start text-navy-700">
            @foreach (ScreenDevices::identifyTips() as $tip)
                <li class="flex gap-2"><x-landing.icon name="check" class="mt-1 size-4 shrink-0 text-emerald-600" /><span>{!! ScreenDevices::format($tip) !!}</span></li>
            @endforeach
        </ul>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-primary min-h-12 rounded-full px-6 text-base">{{ __('Start free') }}</a>
            <a href="{{ route('features.show', 'tv-screens') }}" class="btn-ghost min-h-12 rounded-full px-5 text-base">{{ __('TV menu screens') }}</a>
        </div>
    </div>
</section>
@endsection
