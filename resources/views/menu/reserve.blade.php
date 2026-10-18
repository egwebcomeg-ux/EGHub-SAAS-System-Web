@extends('layouts.base')
@section('title', __('Book a table') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $free = collect($slots)->where('fits', true);
@endphp

@push('head')
@include('menu.partials.reserve-head', ['settings' => $settings])
@endpush

@section('body')
<header class="m-secondary">
    <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-xl bg-white object-contain p-1">@endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black">{{ __('Book a table') }}</h1>
            <p class="truncate text-sm text-white/75">{{ $brand }}</p>
        </div>
        <a href="{{ route('menu.show') }}" class="shrink-0 rounded-full border border-white/30 px-3 py-1.5 text-sm font-semibold">{{ __('Menu') }}</a>
    </div>
</header>

<main class="mx-auto max-w-xl space-y-4 px-4 py-5">
    {{-- 1. branch, day, party size: the page reloads with the free times --}}
    <form method="get" class="card grid gap-3 sm:grid-cols-3" data-pick>
        @if ($branches->count() > 1)
            <label class="block sm:col-span-3">
                <span class="field-label">{{ __('Branch') }}</span>
                <select class="field" name="branch" onchange="this.form.submit()">
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ tr($b->name, $b->name_en) }}@if ($b->address) · {{ $b->address }}@endif</option>
                    @endforeach
                </select>
            </label>
        @else
            <input type="hidden" name="branch" value="{{ $branch->id }}">
        @endif
        <label class="block sm:col-span-2">
            <span class="field-label">{{ __('Day') }}</span>
            <input class="field" type="date" name="date" value="{{ $date->format('Y-m-d') }}" min="{{ $first->format('Y-m-d') }}" max="{{ $last->format('Y-m-d') }}" onchange="this.form.submit()" required>
        </label>
        <label class="block">
            <span class="field-label">{{ __('Guests') }}</span>
            <select class="field" name="party" onchange="this.form.submit()">
                @for ($n = 1; $n <= $config['max_party']; $n++)
                    <option value="{{ $n }}" @selected($n === $party)>{{ $n }}</option>
                @endfor
            </select>
        </label>
        <noscript><button class="btn-ghost sm:col-span-3">{{ __('Show free times') }}</button></noscript>
    </form>

    {{-- 2. time and contact --}}
    <form method="post" action="{{ route('menu.reserve.store') }}" class="card space-y-4">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
        <input type="hidden" name="party_size" value="{{ $party }}">

        <div>
            <h2 class="section-title mb-1">{{ __('Choose the time') }}</h2>
            <p class="muted mb-3 text-sm">{{ $date->locale(app()->getLocale())->translatedFormat('l j/n') }} · {{ __(':n guests', ['n' => $party]) }}</p>
            @if ($free->isEmpty())
                <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">{{ __('No free tables on this day for this number of guests. Try another day.') }}</p>
            @else
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    @foreach ($slots as $s)
                        <label @class(['m-slot flex min-h-12 cursor-pointer items-center justify-center rounded-xl border m-border m-surface text-sm font-bold', 'pointer-events-none opacity-35 line-through' => !$s['fits']])>
                            <input type="radio" name="time" value="{{ $s['time'] }}" class="sr-only" @checked(old('time') === $s['time']) @disabled(!$s['fits']) required>
                            <span dir="ltr">{{ local_time($s['at'], 'h:i A') }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('time')<p class="mt-2 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
        </div>

        <label class="block">
            <span class="field-label">{{ __('Your name') }}</span>
            <input class="field" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name">
            @error('name')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block">
            <span class="field-label">{{ __('Mobile number') }}</span>
            <input class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" autocomplete="tel">
            @error('phone')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block">
            <span class="field-label">{{ __('Notes (optional)') }}</span>
            <textarea class="field" name="notes" rows="2" maxlength="500" placeholder="{{ __('A birthday, a high chair, a quiet corner...') }}">{{ old('notes') }}</textarea>
        </label>
        <button class="m-primary flex min-h-14 w-full items-center justify-center rounded-2xl text-lg font-extrabold shadow-md disabled:opacity-50" @disabled($free->isEmpty())>{{ __('Book the table') }}</button>
        @if (! $config['auto_confirm'])
            <p class="muted text-center text-xs">{{ __('The restaurant confirms your booking; you can follow it on the next page.') }}</p>
        @endif
    </form>
</main>
@endsection
