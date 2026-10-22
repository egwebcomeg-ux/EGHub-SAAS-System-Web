@extends('layouts.dashboard')
@section('title', __('Backups'))

@section('content')
<x-dash.page-header :title="__('Backups')" :help="__('A zip of everything in your restaurant: the menu, settings, tables, customers, orders, loyalty, vouchers and your photos. Weekly by itself, and whenever you ask for one.')">
    @if ($allowed)
        <x-slot:actions>
            <form method="post" action="{{ route('dash.backups.store') }}" data-backup-create>
                @csrf
                <button class="btn-primary" @disabled($blocked !== null) title="{{ $blocked ?? '' }}">{{ __('Create a backup now') }}</button>
            </form>
        </x-slot:actions>
    @endif
</x-dash.page-header>

@if (! $allowed)
    <div class="card max-w-2xl" data-backups-locked>
        <p class="text-navy-700">{{ \App\Services\Entitlements::current()->upgradeMessage('backups') }}</p>
        <p class="mt-2 text-sm text-navy-500">{{ __('With backups, a zip of your whole restaurant is made every week and kept for you, and you can download one any time.') }}</p>
        @can('billing.manage')<a href="{{ route('dash.billing') }}" class="btn-primary mt-4">{{ __('See plans') }}</a>@endcan
    </div>
@else
    @if ($blocked)<p class="mb-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800" data-backup-blocked>{{ $blocked }}</p>@endif
    @error('backup')<p class="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror

    <div class="mb-4 grid gap-3 text-sm text-navy-600 md:grid-cols-3">
        <div class="card"><div class="font-bold text-navy-900">{{ __('What is inside') }}</div><p class="mt-1">{{ __('data/ every table as JSON, excel/ the menu, customers, orders and vouchers as Excel sheets, images/ your uploaded photos, and a README in Arabic.') }}</p></div>
        <div class="card"><div class="font-bold text-navy-900">{{ __('How long it is kept') }}</div><p class="mt-1">{{ __('The last :n weekly backups stay. A backup you create yourself is kept for :days days. Download the ones you want to keep longer.', ['n' => $keepScheduled, 'days' => $keepManualDays]) }}</p></div>
        <div class="card"><div class="font-bold text-navy-900">{{ __('Restoring') }}</div><p class="mt-1">{{ __('The menu comes back from excel/menu.xlsx through Menu › Import from Excel. A full restore (orders, customers, loyalty, wallet) is done by EgWeb support from your file.') }}</p></div>
    </div>

    <section class="card overflow-x-auto">
        <table class="table-base" data-backups-list>
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Size') }}</th><th>{{ __('Status') }}</th><th>{{ __('By') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($backups as $b)
                <tr data-backup="{{ $b->id }}" data-backup-status="{{ $b->status }}">
                    <td class="whitespace-nowrap">{{ local_time($b->created_at, 'd/m/Y h:i A') }}</td>
                    <td>{{ $b->kindLabel() }}</td>
                    <td class="tabular-nums" dir="ltr">{{ $b->isReady() ? $b->sizeLabel() : '—' }}</td>
                    <td>
                        <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $b->isReady(), 'bg-amber-100 text-amber-800' => $b->status === 'building', 'bg-red-100 text-red-700' => $b->status === 'failed'])>{{ $b->statusLabel() }}</span>
                        @if ($b->status === 'failed' && $b->error)<span class="block text-xs text-navy-500">{{ $b->error }}</span>@endif
                        @if ($b->status === 'building')<a href="{{ route('dash.backups') }}" class="block text-xs text-brand-700 underline">{{ __('Refresh') }}</a>@endif
                    </td>
                    <td class="text-navy-600">{{ $b->creator?->name ?? __('Automatic') }}</td>
                    <td class="whitespace-nowrap text-end">
                        @if ($b->isReady())
                            <a href="{{ $links[$b->id] }}" class="btn-ghost" data-backup-download>{{ __('Download') }}</a>
                        @endif
                        @if ($b->status !== 'building')
                            <form method="post" action="{{ route('dash.backups.delete', $b) }}" class="inline" onsubmit="return confirm(@js(__('Delete this backup? This cannot be undone.')))">@csrf<button class="btn-ghost text-red-600">{{ __('Delete') }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-navy-500">{{ __('No backups yet. The first weekly one is made on Sunday night, or create one now.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <p class="mt-3 text-xs text-navy-500">{{ __('Download links work for 30 minutes and only while you are signed in. Keep the file somewhere safe: it holds your customers\' phone numbers.') }}</p>
@endif
@endsection
