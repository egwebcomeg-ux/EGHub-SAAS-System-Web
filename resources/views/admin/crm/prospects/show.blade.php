@extends('layouts.admin')
@section('title', $prospect->name . ' · EgHub Admin')

@section('content')
@php
    $canManage = $admin->allows('crm.manage');
    $canEdit = $canManage || $admin->allows('crm.visits');
    $fill = fn (string $text) => nl2br(e(\App\Services\Crm\CallScript::fill($text, $context)));
    $callers = $team->where('seat', 'caller');
    $fields = $team->where('seat', 'field');
@endphp
@include('admin.crm.partials.tabs')

<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <a href="{{ route('admin.crm.prospects') }}" class="text-sm font-semibold text-navy-500 hover:underline">← {{ __('Places') }}</a>
        <h1 class="page-title mt-1">{{ $prospect->categoryEmoji() }} {{ $prospect->name }}</h1>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-navy-600">
            <span class="badge {{ $prospect->stageClasses() }}" data-stage="{{ $prospect->stage }}">{{ $prospect->stageLabel() }}</span>
            <span>{{ $prospect->categoryLabel() }}</span>
            <span>· {{ $prospect->place() ?: __('No area yet') }}</span>
            <span>· {{ __('Score :n', ['n' => $prospect->score]) }}</span>
            <span>· {{ $prospect->sourceLabel() }}</span>
        </div>
        @if ($prospect->tenant)
            <p class="mt-2 text-sm font-semibold text-teal-800">{{ __('Signed up on EgHub as :name.', ['name' => $prospect->tenant->name]) }}
                @if ($canManage && ($lead = \App\Models\Lead::query()->where('tenant_id', $prospect->tenant_id)->first()))
                    <a class="underline" href="{{ route('admin.crm.show', $lead) }}">{{ __('Open its follow-up') }}</a>
                @endif
            </p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($telUrl)<a href="{{ $telUrl }}" class="btn bg-emerald-600 text-white" data-tel>{{ __('Call') }} <span dir="ltr">{{ $prospect->phone }}</span></a>@endif
        @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn border border-emerald-200 bg-emerald-50 text-emerald-800">{{ __('WhatsApp') }}</a>@endif
        @if ($prospect->hasLocation())<a href="https://www.google.com/maps/dir/?api=1&destination={{ $prospect->lat }},{{ $prospect->lng }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Directions') }}</a>@endif
        @if ($admin->doesFieldVisits() && $prospect->isOpen())
            <form method="post" action="{{ route('admin.crm.visits.walkin', $prospect) }}">@csrf<button class="btn-navy">{{ __('Start a visit now') }}</button></form>
        @endif
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-[1fr_22rem]">
    <div class="space-y-4">
        @if ($canManage)
            {{-- the script, short: the opening, what EgHub is, the objections, the closing --}}
            <section class="card" data-prospect-script>
                <h2 class="section-title mb-2">{{ __('What to say') }}</h2>
                @foreach (['opening', 'pitch', 'closing'] as $kind)
                    @foreach ($sections[$kind] as $item)
                        <div class="mb-3 rounded-2xl bg-navy-50 p-3">
                            <div class="text-xs font-bold text-navy-500">{{ $item->title }}</div>
                            <p class="mt-1 text-[15px] leading-8 text-navy-800">{!! $fill($item->body) !!}</p>
                        </div>
                    @endforeach
                @endforeach
                @if ($sections['objection']->isNotEmpty())
                    <div class="mt-2 space-y-1">
                        @foreach ($sections['objection'] as $item)
                            <details class="rounded-xl border border-navy-100 p-3">
                                <summary class="cursor-pointer font-bold text-navy-800">{{ $item->title }}</summary>
                                <p class="mt-2 text-sm leading-7 text-navy-700">{!! $fill($item->body) !!}</p>
                            </details>
                        @endforeach
                    </div>
                @endif
            </section>

            <form method="post" action="{{ route('admin.crm.prospects.call', $prospect) }}" id="call-form" class="card space-y-3" data-prospect-call>
                @csrf
                <h2 class="section-title">{{ __('Log this call') }}</h2>
                <div class="flex gap-2">
                    @foreach (['call' => __('Phone call'), 'whatsapp' => __('WhatsApp')] as $key => $label)
                        <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-xl border border-navy-200 text-sm font-bold text-navy-700 has-[:checked]:border-navy-800 has-[:checked]:bg-navy-800 has-[:checked]:text-white">
                            <input type="radio" name="channel" value="{{ $key }}" class="sr-only" @checked(old('channel', 'call') === $key)>{{ $label }}
                        </label>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    @foreach (\App\Models\Lead::OUTCOMES as $key => $l)
                        <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 px-2 text-center text-sm font-semibold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800">
                            <input type="radio" name="outcome" value="{{ $key }}" class="sr-only" @checked(old('outcome') === $key) required>{{ \App\Models\Lead::pick($l) }}
                        </label>
                    @endforeach
                </div>
                @error('outcome')<p class="field-error">{{ $message }}</p>@enderror
                <textarea name="notes" rows="2" class="field py-2" placeholder="{{ __('What they said, what they need, what you promised') }}">{{ old('notes') }}</textarea>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="field-label" for="pc-follow">{{ __('Next follow-up') }}</label>
                        <input id="pc-follow" type="datetime-local" name="next_follow_up_at" class="field" dir="ltr" value="{{ old('next_follow_up_at') }}"></div>
                    <div><label class="field-label" for="pc-stage">{{ __('Move to stage') }}</label>
                        <select id="pc-stage" name="stage" class="field" data-stage-select>
                            <option value="">{{ __('Leave it') }}</option>
                            @foreach (\App\Models\Prospect::MANUAL_STAGES as $key)<option value="{{ $key }}">{{ \App\Models\Lead::pick(\App\Models\Prospect::STAGES[$key]) }}</option>@endforeach
                        </select>
                        <select name="lost_reason" class="field mt-2 hidden" data-lost-reason aria-label="{{ __('Why not') }}">
                            @foreach (\App\Models\Lead::LOST_REASONS as $key => $l)<option value="{{ $key }}">{{ \App\Models\Lead::pick($l) }}</option>@endforeach
                        </select></div>
                </div>
                <button class="btn-primary">{{ __('Save the call') }}</button>
            </form>

            {{-- book a visit for the field rep --}}
            <form method="post" action="{{ route('admin.crm.prospects.book', $prospect) }}" class="card space-y-3" data-book-visit>
                @csrf
                <h2 class="section-title">{{ __('Book a visit') }}</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><label class="field-label" for="bk-field">{{ __('Field rep') }}</label>
                        <select id="bk-field" name="field_id" class="field" required>
                            @foreach ($fields as $m)<option value="{{ $m['admin']->id }}" @selected($prospect->field_id === $m['admin']->id)>{{ $m['admin']->name }}</option>@endforeach
                        </select>
                        @error('field_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div><label class="field-label" for="bk-at">{{ __('Day and time') }}</label>
                        <input id="bk-at" type="datetime-local" name="scheduled_at" class="field" dir="ltr" required value="{{ old('scheduled_at') }}">
                        @error('scheduled_at')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div><label class="field-label" for="bk-address">{{ __('Address') }}</label>
                        <input id="bk-address" name="address" class="field" value="{{ old('address', $prospect->address) }}" maxlength="255"></div>
                </div>
                <input name="notes" class="field" placeholder="{{ __('What the rep should know (who to ask for, what they want)') }}" maxlength="1000">
                @if ($fields->isEmpty())<p class="text-sm text-amber-700">{{ __('Add a field rep to the admin team first (role: Field rep).') }}</p>@endif
                <button class="btn-navy" @disabled($fields->isEmpty())>{{ __('Book the visit') }}</button>
            </form>
        @endif

        @if ($appointments->isNotEmpty())
            <section class="card" data-appointments>
                <h2 class="section-title mb-2">{{ __('Visits') }}</h2>
                <ul class="divide-y divide-navy-50">
                    @foreach ($appointments as $a)
                        <li class="py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold">{{ local_time($a->scheduled_at, 'D d/m h:i A') }}</span>
                                <span class="badge {{ $a->statusClasses() }}">{{ $a->statusLabel() }}</span>
                                <span class="text-sm text-navy-500">{{ $a->field?->name }}</span>
                                @if ($a->outcome)<span class="text-sm font-semibold">· {{ $a->outcomeLabel() }}</span>@endif
                                @if ($a->checkedInNear() === false)<span class="badge bg-red-100 text-red-800">{{ __(':m m from the place', ['m' => $a->check_in_distance]) }}</span>@endif
                            </div>
                            @if ($a->answers)
                                <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                                    @foreach ($a->answers as $key => $value)
                                        <div><dt class="inline text-navy-500">{{ \App\Models\Lead::pick(\App\Models\Appointment::QUESTIONS[$key] ?? [$key, $key]) }}:</dt> <dd class="inline font-semibold">{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                            @if ($a->notes)<p class="mt-1 text-sm text-navy-700">{{ $a->notes }}</p>@endif
                            @if ($a->next_step)<p class="mt-1 text-sm font-semibold text-brand-800">{{ __('Next step: :s', ['s' => $a->next_step]) }}</p>@endif
                            @if ($a->photos)
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($a->photos as $i => $_)
                                        <a href="{{ route('admin.crm.appointments.photo', [$a, $i]) }}" target="_blank" class="block size-20 overflow-hidden rounded-xl ring-1 ring-navy-100"><img src="{{ route('admin.crm.appointments.photo', [$a, $i]) }}" alt="{{ __('Visit photo') }}" class="size-full object-cover" loading="lazy"></a>
                                    @endforeach
                                </div>
                            @endif
                            @if ($a->status === 'scheduled' && ($canManage || $a->field_id === $admin->id))
                                <form method="post" action="{{ route('admin.crm.appointments.update', $a) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="datetime-local" name="scheduled_at" class="field max-w-56" dir="ltr" aria-label="{{ __('New time') }}">
                                    <button name="action" value="move" class="btn-ghost">{{ __('Move') }}</button>
                                    <button name="action" value="cancel" class="btn-ghost text-red-700">{{ __('Cancel the visit') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($canEdit)
            <form method="post" action="{{ route('admin.crm.prospects.update', $prospect) }}" class="card space-y-3" data-prospect-edit>
                @csrf
                <h2 class="section-title">{{ __('Place data') }}</h2>
                @include('admin.crm.prospects.fields', ['p' => $prospect])
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>
        @endif
    </div>

    <aside class="space-y-4">
        @if ($canManage)
            <form method="post" action="{{ route('admin.crm.prospects.assign', $prospect) }}" class="card space-y-3" data-prospect-assign>
                @csrf
                <h2 class="section-title">{{ __('Who follows it') }}</h2>
                <div><label class="field-label" for="as-caller">{{ __('Caller') }}</label>
                    <select id="as-caller" name="caller_id" class="field"><option value="">—</option>
                        @foreach ($callers as $m)<option value="{{ $m['admin']->id }}" @selected($prospect->caller_id === $m['admin']->id)>{{ $m['admin']->name }}</option>@endforeach</select></div>
                <div><label class="field-label" for="as-field">{{ __('Field rep') }}</label>
                    <select id="as-field" name="field_id" class="field"><option value="">—</option>
                        @foreach ($fields as $m)<option value="{{ $m['admin']->id }}" @selected($prospect->field_id === $m['admin']->id)>{{ $m['admin']->name }}</option>@endforeach</select></div>
                @unless (in_array($prospect->stage, ['trial', 'won'], true))
                    <div><label class="field-label" for="as-stage">{{ __('Stage') }}</label>
                        <select id="as-stage" name="stage" class="field" data-stage-select>
                            <option value="">{{ __('Leave it') }}</option>
                            @foreach (\App\Models\Prospect::MANUAL_STAGES as $key)<option value="{{ $key }}">{{ \App\Models\Lead::pick(\App\Models\Prospect::STAGES[$key]) }}</option>@endforeach
                        </select>
                        <select name="lost_reason" class="field mt-2 hidden" data-lost-reason aria-label="{{ __('Why not') }}">
                            @foreach (\App\Models\Lead::LOST_REASONS as $key => $l)<option value="{{ $key }}">{{ \App\Models\Lead::pick($l) }}</option>@endforeach
                        </select></div>
                @endunless
                <button class="btn-navy w-full">{{ __('Save') }}</button>
            </form>
        @endif

        @if ($prospect->closer)
            <section class="card text-sm">
                <h2 class="section-title mb-1">{{ __('Commission') }}</h2>
                <p>{{ __('Closed by :name', ['name' => $prospect->closer->name]) }}@if ($prospect->assist) · {{ __('assisted by :name', ['name' => $prospect->assist->name]) }}@endif</p>
            </section>
        @endif

        <section class="card" data-prospect-timeline>
            <h2 class="section-title mb-2">{{ __('Timeline') }}</h2>
            <ol class="space-y-3">
                @forelse ($events as $event)
                    <li class="text-sm">
                        <div class="flex flex-wrap items-center gap-x-2 text-navy-500">
                            <span class="font-bold text-navy-800">{{ $event->typeLabel() }}</span>
                            @if ($event->outcomeLabel())<span>· {{ $event->outcomeLabel() }}</span>@endif
                            <span class="text-xs">· {{ local_time($event->created_at, 'd/m h:i A') }}</span>
                            @if ($event->admin)<span class="text-xs">· {{ $event->admin->name }}</span>@endif
                        </div>
                        @if ($event->notes)<p class="mt-0.5 text-navy-700">{{ $event->notes }}</p>@endif
                    </li>
                @empty
                    <li class="text-sm text-navy-500">{{ __('Nothing yet.') }}</li>
                @endforelse
            </ol>
        </section>
    </aside>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        document.querySelectorAll('form').forEach((form) => {
            const stage = form.querySelector('[data-stage-select]');
            const reason = form.querySelector('[data-lost-reason]');
            if (!stage || !reason) return;
            const sync = () => reason.classList.toggle('hidden', stage.value !== 'lost');
            stage.addEventListener('change', sync);
            sync();
        });
        document.querySelectorAll('[data-locate]').forEach((button) => button.addEventListener('click', () => {
            const form = button.closest('form');
            navigator.geolocation?.getCurrentPosition((pos) => {
                form.querySelector('[name=lat]').value = pos.coords.latitude.toFixed(7);
                form.querySelector('[name=lng]').value = pos.coords.longitude.toFixed(7);
            }, () => alert(button.dataset.error), { enableHighAccuracy: true, timeout: 15000 });
        }));
    })();
</script>
@endpush
