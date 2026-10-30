@extends('layouts.dashboard')
@section('title', __('Reservations'))

@php
    $tones = [
        'pending' => 'bg-amber-100 text-amber-800', 'confirmed' => 'bg-emerald-100 text-emerald-800', 'seated' => 'bg-sky-100 text-sky-800',
        'cancelled' => 'bg-navy-100 text-navy-500', 'no_show' => 'bg-navy-100 text-navy-600',
    ];
    $actions = ['confirmed' => __('Confirm'), 'seated' => __('Seated'), 'no_show' => __('No-show'), 'cancelled' => __('Cancel')];
    $query = fn (array $extra) => array_filter(['branch' => $branch?->id, 'date' => $date->format('Y-m-d')] + $extra);
    $byTime = $day->groupBy(fn ($r) => local_time($r->starts_at, 'H:i'));
    $weekdays = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
@endphp

@section('content')
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Reservations') }}</h1>
        <p class="dash-help">
            @if ($settings['reservations_enabled'] === '1')
                {{ __('Guests book from your menu link:') }} <a href="{{ $guestUrl }}" target="_blank" rel="noopener" class="inline-block break-all font-semibold text-brand-700" dir="ltr">{{ $guestUrl }}</a>
            @else
                <span class="font-semibold text-amber-700">{{ __('Online booking is off. Turn it on in the settings below; you can still add bookings by hand.') }}</span>
            @endif
        </p>
    </div>
    <form class="flex flex-wrap items-end gap-2">
        @if ($branches->count() > 1)
            <select name="branch" class="field min-h-11 w-auto" onchange="this.form.submit()">
                @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch?->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
            </select>
        @endif
        <a href="{{ route('dash.reservations', $query(['date' => $date->copy()->subDay()->format('Y-m-d')])) }}" class="btn-ghost min-h-11 px-3" aria-label="{{ __('Previous day') }}"><span class="inline-block rtl:rotate-180">‹</span></a>
        <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" class="field min-h-11 w-auto" onchange="this.form.submit()">
        <a href="{{ route('dash.reservations', $query(['date' => $date->copy()->addDay()->format('Y-m-d')])) }}" class="btn-ghost min-h-11 px-3" aria-label="{{ __('Next day') }}"><span class="inline-block rtl:rotate-180">›</span></a>
        @unless ($date->isToday())<a href="{{ route('dash.reservations', array_filter(['branch' => $branch?->id])) }}" class="btn-ghost min-h-11 px-3">{{ __('Today') }}</a>@endunless
    </form>
</div>

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        {{-- day view --}}
        <section class="card">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">{{ $date->locale(app()->getLocale())->translatedFormat('l j F') }}</h2>
                <div class="flex gap-2 text-sm">
                    <span class="badge bg-navy-100 text-navy-700">{{ __(':n bookings', ['n' => $day->whereIn('status', \App\Models\Reservation::ACTIVE)->count()]) }}</span>
                    <span class="badge bg-brand-100 text-brand-700">{{ __(':n guests', ['n' => $covers]) }}</span>
                </div>
            </div>
            @forelse ($byTime as $time => $list)
                <div class="flex gap-3 border-t border-navy-100 py-3 first:border-0">
                    <div class="w-20 shrink-0 pt-1 text-lg font-extrabold" dir="ltr">{{ local_time($list->first()->starts_at, 'h:i A') }}</div>
                    <div class="min-w-0 flex-1 space-y-2">
                        @foreach ($list as $r)
                            @include('dashboard.partials.reservation-row', ['r' => $r])
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-navy-500">{{ __('No bookings on this day.') }}</p>
            @endforelse
        </section>

        {{-- to confirm --}}
        @if ($pending->isNotEmpty())
            <section class="card">
                <h2 class="section-title mb-3">{{ __('Waiting for confirmation') }} <span class="badge bg-amber-100 text-amber-800">{{ $pending->count() }}</span></h2>
                <div class="space-y-2">
                    @foreach ($pending as $r)
                        @include('dashboard.partials.reservation-row', ['r' => $r, 'withDate' => true])
                    @endforeach
                </div>
            </section>
        @endif

        <section class="card">
            <h2 class="section-title mb-3">{{ __('Coming up (next 14 days)') }}</h2>
            <div class="space-y-2">
                @forelse ($upcoming as $r)
                    @include('dashboard.partials.reservation-row', ['r' => $r, 'withDate' => true])
                @empty
                    <p class="py-4 text-center text-sm text-navy-500">{{ __('No upcoming bookings.') }}</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="space-y-4">
        {{-- by hand --}}
        <form method="post" action="{{ route('dash.reservations.store') }}" class="card grid gap-3">
            @csrf
            <h2 class="section-title">{{ __('New reservation') }}</h2>
            <p class="muted -mt-2 text-xs">{{ __('For :date. Change the day above to book another day.', ['date' => local_time($date, 'Y/m/d')]) }}</p>
            <input type="hidden" name="branch_id" value="{{ $branch?->id }}">
            <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
            <label class="block">
                <span class="field-label">{{ __('Time') }}</span>
                <select class="field" name="time" required>
                    <option value="">—</option>
                    @foreach ($slots as $s)
                        <option value="{{ $s['time'] }}" @selected(old('time') === $s['time']) @disabled($s['left'] < 1)>{{ local_time($s['at'], 'h:i A') }} · {{ __(':n seats left', ['n' => $s['left']]) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid grid-cols-3 gap-2">
                <label class="block">
                    <span class="field-label">{{ __('Guests') }}</span>
                    <input class="field" type="number" name="party_size" min="1" max="100" value="{{ old('party_size', 2) }}" required>
                </label>
                <label class="col-span-2 block">
                    <span class="field-label">{{ __('Mobile') }}</span>
                    <input class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" dir="ltr" inputmode="tel" placeholder="01xxxxxxxxx">
                </label>
            </div>
            <label class="block">
                <span class="field-label">{{ __('Name') }}</span>
                <input class="field" name="name" value="{{ old('name') }}" required maxlength="100">
            </label>
            <label class="block">
                <span class="field-label">{{ __('Notes') }}</span>
                <input class="field" name="notes" value="{{ old('notes') }}" maxlength="500">
            </label>
            <button class="btn-primary" @disabled(!$branch)>{{ __('Save reservation') }}</button>
        </form>

        {{-- rules --}}
        @if ($canSettings)
            <details class="card" @if ($settings['reservations_enabled'] !== '1') open @endif>
                <summary class="section-title cursor-pointer">{{ __('Booking settings') }}</summary>
                <form method="post" action="{{ route('dash.reservations.settings') }}" class="mt-3 grid min-w-0 gap-3 [&_label]:min-w-0">
                    @csrf
                    <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="reservations_enabled" value="1" @checked($settings['reservations_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Guests can book from the menu link') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto_confirm" value="1" @checked($config['auto_confirm']) class="size-4 accent-brand-600"> {{ __('Confirm guest bookings automatically') }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="field-label">{{ __('Slot every') }}</span>
                            <select class="field" name="slot_minutes">
                                @foreach (\App\Http\Controllers\Dashboard\ReservationsController::SLOT_CHOICES as $m)
                                    <option value="{{ $m }}" @selected($config['slot'] === $m)>{{ __(':n min', ['n' => $m]) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Table time (minutes)') }}</span>
                            <input class="field" type="number" name="duration_minutes" min="30" max="360" value="{{ $config['duration'] }}" required>
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Largest group') }}</span>
                            <input class="field" type="number" name="max_party" min="1" max="100" value="{{ $config['max_party'] }}" required>
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Book at least (hours ahead)') }}</span>
                            <input class="field" type="number" name="lead_hours" min="0" max="72" value="{{ $config['lead_hours'] }}" required>
                        </label>
                        <label class="col-span-2 block">
                            <span class="field-label">{{ __('Book up to (days ahead)') }}</span>
                            <input class="field" type="number" name="horizon_days" min="1" max="180" value="{{ $config['horizon_days'] }}" required>
                        </label>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="field-label">{{ __('Seats for bookings at the same time') }}</legend>
                        @foreach ($allBranches as $b)
                            <label class="flex items-center justify-between gap-2 text-sm">
                                <span>{{ tr($b->name, $b->name_en) }}</span>
                                <input class="field w-24" type="number" name="seats[{{ $b->id }}]" min="0" max="2000" value="{{ $seats[$b->id] ?? \App\Services\Reservations\ReservationService::DEFAULT_SEATS }}">
                            </label>
                        @endforeach
                    </fieldset>

                    <fieldset class="space-y-2" data-hours>
                        <legend class="field-label">{{ __('Booking hours') }}</legend>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="hours_mode" value="branch" @checked(!$hours) class="accent-brand-600"> {{ __('Same as the branch working hours') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="hours_mode" value="custom" @checked($hours) class="accent-brand-600"> {{ __('Custom hours per day (leave empty = closed)') }}</label>
                        <div class="space-y-1.5" data-custom-hours>
                            @foreach ($weekdays as $d => $label)
                                <div class="grid grid-cols-[3.5rem_minmax(0,1fr)_minmax(0,1fr)] items-center gap-1.5 text-sm">
                                    <span>{{ $label }}</span>
                                    <input class="field min-h-9 px-1.5 py-0" type="time" name="hours[{{ $d }}][open]" value="{{ $hours[$d]['open'] ?? '13:00' }}" aria-label="{{ __('From') }}">
                                    <input class="field min-h-9 px-1.5 py-0" type="time" name="hours[{{ $d }}][close]" value="{{ $hours[$d]['close'] ?? '23:00' }}" aria-label="{{ __('To') }}">
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                    <button class="btn-primary">{{ __('Save') }}</button>
                </form>
            </details>
        @endif
    </div>
</div>

<script>
    (() => {
        const box = document.querySelector('[data-hours]');
        if (!box) return;
        const sync = () => box.querySelector('[data-custom-hours]').classList.toggle('hidden', box.querySelector('[name="hours_mode"]:checked')?.value !== 'custom');
        box.addEventListener('change', sync);
        sync();
    })();
</script>
@endsection
