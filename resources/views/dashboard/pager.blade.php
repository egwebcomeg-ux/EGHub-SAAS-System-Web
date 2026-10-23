@extends('layouts.base', ['viteEntries' => ['resources/js/pager-station.js']])
@section('title', __('Pager station'))

@section('body')
@php
    $i18n = \App\Services\Orders\Pager::staffTexts() + [
        'waiting' => __('Waiting for the call'), 'calledStatus' => __('Called'), 'picked_up' => __('Picked up'), 'expired' => __('Expired'),
        'pickedBtn' => __('Picked up'), 'qr' => __('QR'), 'empty' => __('No tickets yet. Type the order number and show the QR to the guest.'),
        'scanTitle' => __('Scan so we can call you when your order is ready'), 'next' => __('Next number #:n', ['n' => '__N__']),
        'sure' => __('Are you sure?'),
    ];
@endphp
<div class="dash-app flex min-h-dvh flex-col bg-navy-50 text-navy-900" id="pager-station"
     data-feed="{{ route('dash.pager.feed') }}"
     data-store="{{ route('dash.pager.tickets.store') }}"
     data-poll="{{ $poll }}"
     data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'>
    <header class="flex flex-wrap items-center gap-3 border-b border-navy-100 bg-white px-4 py-2.5">
        <a href="{{ route('dash.home') }}" class="grid size-11 place-items-center rounded-xl text-lg text-navy-500 hover:bg-navy-50" aria-label="{{ __('Dashboard') }}">←</a>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold">📣 {{ __('Pager station') }}</h1>
            <p class="truncate text-xs text-navy-500">{{ tr($branch->name, $branch->name_en) }} · {{ __('The guest scans, keeps the page open, and their phone rings when you call.') }}</p>
        </div>
        <div class="ms-auto flex items-center gap-2 text-sm">
            <span class="flex items-center gap-1.5 text-xs text-navy-500"><span class="size-2 rounded-full bg-emerald-500" data-live-dot></span>{{ __('Live') }}</span>
            <button type="button" class="min-h-11 rounded-xl bg-navy-50 px-3 font-semibold hover:bg-navy-100" data-fullscreen>⛶ {{ __('Full screen') }}</button>
        </div>
    </header>
    @if (session('ok'))<p class="bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800">{{ session('ok') }}</p>@endif

    <div class="grid flex-1 gap-4 p-4 md:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        {{-- new ticket: the other POS's number on a big keypad, or the next number --}}
        <section class="flex flex-col rounded-3xl bg-white p-4 shadow-sm ring-1 ring-navy-100" data-new>
            <h2 class="text-sm font-bold text-navy-500">{{ __('New ticket') }}</h2>
            <label class="sr-only" for="pager-number">{{ __('Order number') }}</label>
            <input id="pager-number" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" dir="ltr"
                   class="mt-2 h-16 w-full rounded-2xl border border-navy-200 bg-navy-50 text-center text-4xl font-black tabular-nums tracking-wider focus:border-brand-500 focus:outline-none"
                   placeholder="#" data-number>
            <div class="mt-3 grid grid-cols-3 gap-2" dir="ltr" data-keypad>
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $k)
                    <button type="button" class="min-h-14 rounded-2xl bg-navy-50 text-2xl font-extrabold hover:bg-navy-100" data-key="{{ $k }}">{{ $k }}</button>
                @endforeach
                <button type="button" class="min-h-14 rounded-2xl bg-navy-50 text-xl font-bold hover:bg-navy-100" data-key="clear" aria-label="{{ __('Clear the number') }}">C</button>
                <button type="button" class="min-h-14 rounded-2xl bg-navy-50 text-2xl font-extrabold hover:bg-navy-100" data-key="0">0</button>
                <button type="button" class="min-h-14 rounded-2xl bg-navy-50 text-xl font-bold hover:bg-navy-100" data-key="back" aria-label="{{ __('Delete') }}">⌫</button>
            </div>
            <p class="mt-2 hidden rounded-xl bg-red-50 p-2 text-sm font-semibold text-red-700" data-error></p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="btn-primary min-h-14 text-lg" data-create>{{ __('Show the QR') }}</button>
                <button type="button" class="btn-ghost min-h-14 flex-col gap-0 border leading-tight" data-create-next>
                    <span class="text-xl font-black" dir="ltr">+1</span><span class="text-xs font-semibold text-navy-500" data-next-label>{{ __('Next number #:n', ['n' => $next]) }}</span>
                </button>
            </div>

            @can('settings.manage')
                <details class="mt-auto pt-4 text-sm">
                    <summary class="cursor-pointer font-bold text-navy-600">⚙ {{ __('Pager settings') }}</summary>
                    <form method="POST" action="{{ route('dash.pager.settings') }}" class="mt-3 space-y-3">
                        @csrf
                        <label class="flex items-start gap-2"><input type="checkbox" name="auto_call" value="1" class="mt-1" @checked($autoCall)>
                            <span><b>{{ __('Call the guest by itself when the order is ready') }}</b><span class="block text-xs text-navy-500">{{ __('For orders from this system (POS, kiosk, online takeaway) with the QR on the receipt.') }}</span></span></label>
                        <label class="flex items-center gap-2">{{ __('Close open tickets after') }}
                            <input class="field min-h-10 w-20" type="number" name="expire_hours" min="1" max="{{ \App\Services\Orders\Pager::MAX_EXPIRE_HOURS }}" value="{{ $expireHours }}"> {{ __('hours') }}</label>
                        <button class="btn-primary min-h-10 px-4">{{ __('Save') }}</button>
                    </form>
                </details>
            @endcan
        </section>

        {{-- the queue: open tickets oldest first, with the guest's page status --}}
        <section class="flex min-h-0 flex-col rounded-3xl bg-white p-4 shadow-sm ring-1 ring-navy-100">
            <h2 class="flex items-center justify-between text-sm font-bold text-navy-500">{{ __('Queue') }} <span class="badge bg-navy-100 text-navy-700" data-open-count>0</span></h2>
            <ul class="mt-2 flex-1 divide-y divide-navy-100 overflow-y-auto" data-queue></ul>
        </section>
    </div>

    {{-- the big QR for the guest, right after a ticket is opened --}}
    <dialog class="m-auto w-[min(92vw,30rem)] rounded-3xl p-0 backdrop:bg-navy-900/60" data-qr-dialog>
        <div class="p-6 text-center">
            <div class="text-sm font-bold text-navy-500">{{ __('Order number') }}</div>
            <div class="text-6xl font-black tabular-nums" dir="ltr" data-qr-number></div>
            <p class="mt-2 text-lg font-extrabold">📣 {{ __('Scan so we can call you when your order is ready') }}</p>
            <div class="mx-auto mt-3 w-fit rounded-2xl bg-white p-2 ring-1 ring-navy-100 [&_svg]:size-64" data-qr-code></div>
            <p class="mt-2 text-sm text-navy-500">{{ __('Keep the page open on your phone. No app needed.') }}</p>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="#" target="_blank" rel="noopener" class="btn-ghost min-h-12 justify-center border" data-qr-print>🖨 {{ __('Print ticket') }}</a>
                <button type="button" class="btn-primary min-h-12" data-qr-close>{{ __('Done') }}</button>
            </div>
        </div>
    </dialog>
</div>
@endsection
