@extends('layouts.admin')
@section('title', __('Phone calls') . ' · EgHub Admin')

@section('content')
@include('admin.crm.partials.tabs')

<div class="mb-4">
    <h1 class="page-title">{{ __('Phone calls') }}</h1>
    <p class="muted">{{ __('Every call of the linked sales phones: who called, how long, and what was said. The sound is not recorded.') }}</p>
</div>

<div class="mb-4 grid grid-cols-2 gap-2 lg:grid-cols-4" data-call-counts>
    @foreach ([
        ['today', __('Calls today'), number_format($counts['today']), route('admin.crm.calls'), 'text-navy-900'],
        ['seconds', __('Talk time today'), \App\Models\SalesCall::formatDuration($counts['seconds']), route('admin.crm.calls'), 'text-navy-900'],
        ['missed', __('Missed, not returned'), number_format($counts['missed']), route('admin.crm.calls', ['show' => 'missed']), $counts['missed'] > 0 ? 'text-red-700' : 'text-navy-300'],
        ['unsummarized', __('Waiting for a summary'), number_format($counts['unsummarized']), route('admin.crm.calls', ['show' => 'unsummarized']), $counts['unsummarized'] > 0 ? 'text-brand-700' : 'text-navy-300'],
    ] as [$key, $label, $value, $url, $tone])
        <a href="{{ $url }}" class="card block p-3 hover:shadow-md" data-call-count="{{ $key }}">
            <div class="text-xs font-semibold text-navy-500 sm:text-sm">{{ $label }}</div>
            <div class="mt-0.5 text-2xl font-black tabular-nums {{ $tone }}">{{ $value }}</div>
        </a>
    @endforeach
</div>

@if ($team->isNotEmpty())
    <section class="card mb-4" data-call-team>
        <h2 class="section-title mb-2">{{ __('The team today') }}</h2>
        <ul class="divide-y divide-navy-50">
            @foreach ($team as $row)
                <li class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span class="font-semibold text-navy-800">{{ $row->admin?->name ?? '—' }}</span>
                    <span class="tabular-nums text-navy-600">{{ __(':n calls', ['n' => number_format((int) $row->calls)]) }} · {{ \App\Models\SalesCall::formatDuration((int) $row->seconds) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<nav class="mb-3 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Phone calls') }}">
    @foreach (['' => __('All'), 'missed' => __('Missed, not returned'), 'unsummarized' => __('Waiting for a summary'), 'unknown' => __('New numbers')] as $key => $label)
        <a href="{{ route('admin.crm.calls', array_filter(['show' => $key])) }}" @class(['inline-flex min-h-10 shrink-0 items-center rounded-full border px-3.5 text-sm font-semibold',
            'border-navy-800 bg-navy-800 text-white' => $filter === $key, 'border-navy-200 bg-white text-navy-700 hover:bg-navy-50' => $filter !== $key])>{{ $label }}</a>
    @endforeach
</nav>

<div class="card mb-4 p-0">
    <ul class="divide-y divide-navy-50" data-call-list>
        @forelse ($calls as $call)
            <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5" data-call-row="{{ $call->id }}">
                <a href="{{ route('admin.crm.calls.show', $call) }}" class="min-w-0 flex-1 basis-56">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-navy-900">{{ $call->contactName() }}</span>
                        <span class="badge {{ $call->directionClasses() }}">{{ $call->directionLabel() }}</span>
                        @if ($call->returned_at)<span class="badge bg-emerald-100 text-emerald-800">{{ __('Returned') }}</span>@endif
                    </span>
                    <span class="mt-1 block text-xs text-navy-500">
                        @if ($call->phone)<bdi dir="ltr">{{ $call->phone }}</bdi> · @endif{{ local_time($call->started_at, 'Y/m/d h:i A') }} · <span class="tabular-nums">{{ $call->durationLabel() }}</span>@if ($call->admin) · {{ $call->admin->name }}@endif
                    </span>
                    @if ($call->notes)<span class="mt-1 block truncate text-sm text-navy-700">{{ $call->notes }}</span>@endif
                </a>
                <div class="flex gap-2">
                    @if ($call->wasTalked() && ! $call->summarized_at)
                        <a href="{{ route('admin.crm.calls.show', $call) }}" class="btn-primary min-h-10 px-3 text-sm">{{ __('Write the summary') }}</a>
                    @endif
                    @if ($call->phone)
                        <a href="tel:{{ $call->phone }}" class="btn min-h-10 border border-emerald-200 bg-emerald-50 px-3 text-sm text-emerald-800">{{ __('Call') }}</a>
                    @endif
                </div>
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-navy-500">{{ __('No calls here yet.') }}</li>
        @endforelse
    </ul>
</div>
<div class="mb-4">{{ $calls->links() }}</div>

<section class="card" data-call-devices>
    <h2 class="section-title">{{ __('Your phone') }}</h2>
    <p class="muted mt-1">{{ __('Open this page from the EgHub Sales app on the office phone and link it. Its calls reach here by themselves.') }}</p>
    <ul class="mt-3 divide-y divide-navy-50">
        @forelse ($devices as $device)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span><span class="font-semibold text-navy-800">{{ $device->name }}</span>
                    <span class="text-xs text-navy-500">· {{ __('Last seen :at', ['at' => $device->last_seen_at ? local_time($device->last_seen_at, 'Y/m/d h:i A') : '—']) }}</span></span>
                <form method="post" action="{{ route('admin.crm.devices.unlink', $device) }}">@csrf<button class="btn-ghost min-h-9 px-3 text-xs text-red-600">{{ __('Unlink') }}</button></form>
            </li>
        @empty
            <li class="py-2 text-sm text-navy-500">{{ __('No phone linked yet.') }}</li>
        @endforelse
    </ul>
    <div class="mt-3 hidden" data-link-device-box>
        <button type="button" class="btn-primary" data-link-device data-url="{{ route('admin.crm.devices.link') }}" data-done="{{ __('This phone is linked.') }}" data-failed="{{ __('It did not link. Try again.') }}">{{ __('Link this phone') }}</button>
        <p class="mt-2 text-sm font-semibold text-navy-700" data-link-device-status role="status"></p>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (() => {
        const native = window.EgHubNative;
        const box = document.querySelector('[data-link-device-box]');
        if (!box || !native || typeof native.linkSalesDevice !== 'function') return;
        box.classList.remove('hidden');
        const button = box.querySelector('[data-link-device]');
        const status = box.querySelector('[data-link-device-status]');
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const info = JSON.parse(native.getInfo());
                const response = await fetch(button.dataset.url, { method: 'POST', headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                }, body: JSON.stringify({ name: [info.manufacturer, info.model].filter(Boolean).join(' ') }) });
                const data = await response.json();
                const linked = JSON.parse(native.linkSalesDevice(data.token));
                status.textContent = linked.ok ? button.dataset.done : button.dataset.failed;
            } catch (error) {
                status.textContent = button.dataset.failed;
            }
            button.disabled = false;
        });
    })();
</script>
@endpush
