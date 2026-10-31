@extends('layouts.admin')
@section('title', __('System health') . ' · EgHub Admin')

@section('content')
@php
    $ago = fn (?int $at) => $at ? now()->setTimestamp($at)->diffForHumans() : __('Never recorded');
@endphp
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('System health') }}</h1>
        <p class="text-sm text-navy-500">{{ __('The same checks as after every deploy, the background jobs, the backups and the outside services.') }}</p>
    </div>
    <span @class(['badge text-sm', 'bg-red-100 text-red-800' => $failing > 0, 'bg-amber-100 text-amber-800' => $failing === 0 && $warnings > 0, 'bg-emerald-100 text-emerald-800' => $failing === 0 && $warnings === 0]) data-system-status>
        @if ($failing > 0)
            {{ __(':n checks failed', ['n' => $failing]) }}
        @elseif ($warnings > 0)
            {{ __('Working, :n to look at', ['n' => $warnings]) }}
        @else
            {{ __('All good') }}
        @endif
    </span>
</div>

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        [__('Jobs waiting'), $jobs['waiting'] ?? '—', ($jobs['waiting'] ?? 0) > 50 ? 'text-amber-700' : 'text-navy-900', __('Emails, notifications and backups in the queue')],
        [__('Failed jobs'), $jobs['failed'] ?? '—', ($jobs['failed'] ?? 0) > 0 ? 'text-red-700' : 'text-navy-900', __('Background work that gave up')],
        [__('Last backup'), $ago($times['backup']), 'text-navy-900', $latestBackup ? $latestBackup['name'] : __('No backup file yet')],
        [__('Scheduler'), $ago($times['scheduler']), 'text-navy-900', __('Runs every minute from cron')],
    ] as [$label, $value, $tone, $hint])
        <div class="card p-4">
            <div class="text-sm font-semibold text-navy-500">{{ $label }}</div>
            <div class="mt-1 truncate text-xl font-black {{ $tone }}">{{ $value }}</div>
            <div class="mt-1 truncate text-xs text-navy-500" dir="auto">{{ $hint }}</div>
        </div>
    @endforeach
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <section class="card" data-system-checks>
        <h2 class="section-title mb-3">{{ __('Deploy checklist') }}</h2>
        <ul class="divide-y divide-navy-50">
            @foreach ($checks as $check)
                <li class="flex items-start gap-3 py-2" dir="ltr">
                    @if ($check['ok'])
                        <span class="badge shrink-0 bg-emerald-100 text-emerald-800">OK</span>
                    @elseif ($check['warn'])
                        <span class="badge shrink-0 bg-amber-100 text-amber-800">WARN</span>
                    @else
                        <span class="badge shrink-0 bg-red-100 text-red-800">FAIL</span>
                    @endif
                    <div class="min-w-0 text-start text-sm">
                        <div class="font-semibold text-navy-900">{{ $check['label'] }}</div>
                        @if ($check['hint'] !== '' && ! $check['ok'])
                            <div class="break-words text-xs text-navy-500">{{ \Illuminate\Support\Str::limit($check['hint'], 240) }}</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="space-y-4">
        <section class="card" data-system-services>
            <h2 class="section-title mb-3">{{ __('Outside services') }}</h2>
            <ul class="divide-y divide-navy-50">
                @foreach ($services as [$label, $on, $note])
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold">{{ $label }}</div>
                            @if ($note !== '')<div class="truncate text-xs text-navy-500" dir="auto">{{ $note }}</div>@endif
                        </div>
                        <span class="badge shrink-0 {{ $on ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $on ? __('Set up') : __('Not set up') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card">
            <h2 class="section-title mb-3">{{ __('Backups') }}</h2>
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-navy-500">{{ __('Platform backup') }}</dt><dd class="font-semibold">{{ $ago($times['backup']) }}</dd>
                <dt class="text-navy-500">{{ __('Restaurant backups (weekly)') }}</dt><dd class="font-semibold">{{ $ago($times['tenant_backups']) }}</dd>
                @if ($latestBackup)
                    <dt class="text-navy-500">{{ __('Newest file') }}</dt>
                    <dd class="truncate font-semibold" dir="ltr">{{ $latestBackup['name'] }} · {{ number_format($latestBackup['size'] / 1048576, 1) }} MB</dd>
                @endif
            </dl>
        </section>

        <section class="card">
            <h2 class="section-title mb-2">{{ __('Versions') }}</h2>
            <p class="text-sm text-navy-600" dir="ltr">PHP {{ $versions['php'] }} · Laravel {{ $versions['laravel'] }} · {{ $versions['env'] }}</p>
        </section>
    </div>
</div>

@if ($failedJobs)
    <section class="card mt-4 overflow-x-auto" data-failed-jobs>
        <h2 class="section-title mb-3">{{ __('Last failed jobs') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Job') }}</th><th>{{ __('Error') }}</th></tr></thead>
            <tbody>
            @foreach ($failedJobs as $job)
                <tr>
                    <td class="whitespace-nowrap">{{ local_time($job['at'], 'm/d h:i A') }}</td>
                    <td dir="ltr" class="text-start">{{ class_basename($job['job']) }}<div class="text-xs text-navy-400">{{ $job['queue'] }}</div></td>
                    <td dir="ltr" class="min-w-[260px] text-start text-xs text-navy-600">{{ $job['error'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif

<section class="card mt-4" data-deploy-log>
    <h2 class="section-title mb-3">{{ __('Automatic deploys') }}</h2>
    @if ($deployLog)
        <pre class="max-h-80 overflow-auto rounded-xl bg-navy-900 p-3 text-xs leading-relaxed text-white/90" dir="ltr">{{ implode("\n", $deployLog) }}</pre>
    @else
        <p class="text-sm text-navy-500">{{ __('No automatic deploy has run on this server yet.') }}</p>
    @endif
</section>
@endsection
