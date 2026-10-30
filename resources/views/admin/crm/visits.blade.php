@extends('layouts.admin')
@section('title', __('My visits') . ' · EgHub Admin')

@section('content')
@include('admin.crm.partials.tabs')

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('My visits') }}</h1>
        <p class="muted">{{ local_time(now(), 'l d/m/Y') }}</p>
    </div>
    <a href="{{ route('admin.crm.prospects') }}#new-place" class="btn-primary">{{ __('Add a place I found') }}</a>
</div>

@include('admin.crm.partials.progress', ['card' => $card])

@foreach ([[__('Late visits (report or move them)'), $late, true], [__('Today'), $day['appointments'], false]] as [$title, $list, $isLate])
    @if ($list->isNotEmpty() || ! $isLate)
        <section class="mb-4" data-visit-list="{{ $isLate ? 'late' : 'today' }}">
            <h2 class="section-title mb-2">{{ $title }}</h2>
            @forelse ($list as $a)
                @php($p = $a->prospect)
                <article id="visit-{{ $a->id }}" class="card mb-3 space-y-3" data-visit="{{ $a->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-brand-700">{{ local_time($a->scheduled_at, $isLate ? 'D d/m h:i A' : 'h:i A') }}</div>
                            <a href="{{ route('admin.crm.prospects.show', $p) }}" class="text-lg font-black text-navy-900">{{ $p->categoryEmoji() }} {{ $p->name }}</a>
                            <div class="text-sm text-navy-600">{{ $a->address ?: $p->address }} {{ $p->place() ? '· '.$p->place() : '' }}</div>
                            @if ($a->notes)<div class="mt-1 text-sm text-navy-700">{{ $a->notes }}</div>@endif
                            @if ($a->setter)<div class="text-xs text-navy-500">{{ __('Booked by :name', ['name' => $a->setter->name]) }}</div>@endif
                        </div>
                        <span class="badge {{ $a->statusClasses() }}">{{ $a->statusLabel() }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($p->phone)<a href="tel:{{ $p->phone }}" class="btn border border-emerald-200 bg-emerald-50 text-emerald-800">{{ __('Call') }}</a>@endif
                        @if ($p->hasLocation())<a href="https://www.google.com/maps/dir/?api=1&destination={{ $p->lat }},{{ $p->lng }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Directions') }}</a>@endif
                    </div>
                    @if ($a->status === 'scheduled')
                        @if (! $a->checked_in_at)
                            <form method="post" action="{{ route('admin.crm.visits.checkin', $a) }}" data-checkin-form>
                                @csrf
                                <input type="hidden" name="lat"><input type="hidden" name="lng">
                                <button type="button" class="btn-navy w-full" data-checkin data-error="{{ __('Could not read your location. Allow location for this site.') }}">📍 {{ __('I arrived: check in') }}</button>
                            </form>
                        @else
                            <p class="text-sm font-semibold {{ $a->checkedInNear() === false ? 'text-red-700' : 'text-emerald-700' }}">
                                {{ __('Checked in :at', ['at' => local_time($a->checked_in_at, 'h:i A')]) }}
                                @if ($a->check_in_distance !== null) · {{ __(':m m from the place', ['m' => $a->check_in_distance]) }}@endif
                            </p>
                        @endif
                        <details @if ($a->checked_in_at) open @endif class="rounded-2xl border border-navy-100 p-3">
                            <summary class="cursor-pointer font-bold text-navy-800">{{ __('Visit report') }}</summary>
                            <form method="post" action="{{ route('admin.crm.visits.report', $a) }}" enctype="multipart/form-data" class="mt-3 space-y-3" data-report-form>
                                @csrf
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach (\App\Models\Appointment::OUTCOMES as $key => $l)
                                        <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 px-2 text-center text-sm font-semibold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800">
                                            <input type="radio" name="outcome" value="{{ $key }}" class="sr-only" required>{{ \App\Models\Lead::pick($l) }}
                                        </label>
                                    @endforeach
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    @foreach (\App\Models\Appointment::QUESTIONS as $key => [$ar, $enLabel, $type])
                                        <div><label class="field-label" for="q-{{ $a->id }}-{{ $key }}">{{ \App\Models\Lead::pick([$ar, $enLabel]) }}</label>
                                            @if ($type === 'yesno')
                                                <select id="q-{{ $a->id }}-{{ $key }}" name="answers[{{ $key }}]" class="field"><option value="">—</option><option value="yes">{{ __('Yes') }}</option><option value="no">{{ __('No') }}</option></select>
                                            @else
                                                <input id="q-{{ $a->id }}-{{ $key }}" name="answers[{{ $key }}]" class="field" @if ($type === 'number') type="number" min="0" inputmode="numeric" @endif maxlength="200">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <div><label class="field-label" for="ph-{{ $a->id }}">{{ __('Photos (the menu, the front of the place)') }}</label>
                                    <input id="ph-{{ $a->id }}" type="file" name="photos[]" accept="image/*" capture="environment" multiple class="field py-2"></div>
                                <textarea name="notes" rows="2" class="field py-2" placeholder="{{ __('What happened, what they asked for') }}"></textarea>
                                <input name="next_step" class="field" placeholder="{{ __('Next step (send the offer, menu entry, call on Sunday...)') }}" maxlength="255">
                                <div><label class="field-label" for="nv-{{ $a->id }}">{{ __('Another visit (optional)') }}</label>
                                    <input id="nv-{{ $a->id }}" type="datetime-local" name="next_visit_at" class="field" dir="ltr"></div>
                                <button class="btn-primary w-full">{{ __('Save the report') }}</button>
                            </form>
                        </details>
                        <form method="post" action="{{ route('admin.crm.appointments.update', $a) }}" class="flex gap-2">
                            @csrf
                            <button name="action" value="no_show" class="btn-ghost flex-1">{{ __('Nobody there') }}</button>
                        </form>
                    @endif
                </article>
            @empty
                <p class="card py-6 text-center text-sm text-navy-500">{{ __('No visits booked for today. Use the suggested places below.') }}</p>
            @endforelse
        </section>
    @endif
@endforeach

@if ($day['suggestions'])
    <section class="card mb-4" data-route-suggestions>
        <h2 class="section-title">{{ __('Suggested on your way') }}</h2>
        <p class="mb-2 text-xs text-navy-500">{{ __('Your best recommended places, nearest first from your last visit.') }}</p>
        <ol class="divide-y divide-navy-50">
            @foreach ($day['suggestions'] as $i => $s)
                @php($p = $s['prospect'])
                <li class="flex items-center gap-3 py-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-navy-900 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <a href="{{ route('admin.crm.prospects.show', $p) }}" class="min-w-0 flex-1">
                        <span class="font-bold">{{ $p->categoryEmoji() }} {{ $p->name }}</span>
                        <span class="block text-xs text-navy-500">{{ $p->place() ?: '—' }} · {{ $s['metres'] >= 1000 ? __(':km km', ['km' => round($s['metres'] / 1000, 1)]) : __(':m m', ['m' => $s['metres']]) }} · {{ __('Score :n', ['n' => $p->score]) }}</span>
                    </a>
                    <form method="post" action="{{ route('admin.crm.visits.walkin', $p) }}">@csrf<button class="btn-ghost">{{ __('Visit') }}</button></form>
                </li>
            @endforeach
        </ol>
    </section>
@endif

@if ($upcoming->isNotEmpty())
    <section class="card" data-upcoming>
        <h2 class="section-title mb-2">{{ __('Next days') }}</h2>
        <ul class="divide-y divide-navy-50">
            @foreach ($upcoming as $a)
                <li class="py-2"><span class="font-bold">{{ local_time($a->scheduled_at, 'D d/m h:i A') }}</span> · <a class="hover:underline" href="{{ route('admin.crm.prospects.show', $a->prospect) }}">{{ $a->prospect->name }}</a> <span class="text-xs text-navy-500">{{ $a->prospect->place() }}</span></li>
            @endforeach
        </ul>
    </section>
@endif
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-checkin]').forEach((button) => button.addEventListener('click', () => {
        const form = button.closest('form');
        button.disabled = true;
        navigator.geolocation?.getCurrentPosition((pos) => {
            form.querySelector('[name=lat]').value = pos.coords.latitude.toFixed(7);
            form.querySelector('[name=lng]').value = pos.coords.longitude.toFixed(7);
            form.submit();
        }, () => { button.disabled = false; alert(button.dataset.error); }, { enableHighAccuracy: true, timeout: 20000 });
    }));
</script>
@endpush
