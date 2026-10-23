@extends('layouts.dashboard')
@section('title', __('Tables & QR'))

@section('content')
<x-dash.page-header :title="__('Tables & QR')" :help="__('Print a QR card for each table; guests scan it to open the menu.')">
    <p class="mt-0.5 text-xs font-semibold text-navy-500">{{ __('Tables') }}: {{ $usage['used'] }} / {{ $usage['limit'] ?? '∞' }}</p>
    <x-slot:actions>
        <a href="{{ route('dash.tables.print') }}" target="_blank" class="btn-primary">{{ __('Print all table QR cards') }}</a>
        <a href="{{ route('dash.tables.print', ['menu' => 1]) }}" target="_blank" class="btn-ghost">{{ __('Print general menu QR') }}</a>
        @if (\App\Services\Entitlements::current()->allows('customer_display'))
            <a href="{{ tenant()->url('board') }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Order-number board (hall screen)') }}</a>
        @endif
    </x-slot:actions>
</x-dash.page-header>

<div class="space-y-4">
    <section class="card">
        <h2 class="mb-3 text-lg font-bold">{{ __('Your menu link') }}</h2>
        @include('dashboard.partials.menu-links')
        <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-navy-50 p-3 text-sm" data-qr-target="{{ $qrTarget }}">
            <span>{{ __('Links page') }}: <bdi dir="ltr" class="break-all"><code class="rounded-lg bg-white px-2 py-1">{{ tenant()->url('links') }}</code></bdi></span>
            <span class="font-semibold">{{ $qrTarget === 'links' ? __('Your QR codes open the links page.') : __('Your QR codes open the menu.') }}</span>
            @can('settings.manage')<a href="{{ route('dash.settings') }}#links-page" class="text-brand-700 underline">{{ __('Change') }}</a>@endcan
        </div>
    </section>
    @foreach ($branches as $branch)
        <section class="card">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">{{ tr($branch->name, $branch->name_en) }}</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="post" action="{{ route('dash.tables.store') }}" class="flex flex-wrap items-center gap-2 rounded-xl bg-navy-50/70 p-1.5 ps-3">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                        <span class="text-sm">{{ __('Add tables from') }}</span>
                        <input class="field w-16 px-2 text-center" name="from" type="number" min="1" max="999" value="{{ $branch->tables->count() + 1 }}" required>
                        <span class="text-sm">{{ __('to') }}</span>
                        <input class="field w-16 px-2 text-center" name="to" type="number" min="1" max="999" value="{{ $branch->tables->count() + 1 }}" required>
                        <button class="btn-primary px-4">{{ __('Add') }}</button>
                    </form>
                    <a href="{{ route('dash.tables.print', ['branch' => $branch->id]) }}" target="_blank" class="btn-ghost">{{ __('Print') }}</a>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($branch->tables as $t)
                    <div @class(['rounded-xl border p-3', 'border-navy-100' => $t->is_active, 'border-dashed border-navy-200 opacity-60' => !$t->is_active])>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <span class="font-extrabold sm:text-lg">{{ __('Table :name', ['name' => $t->name]) }}</span>
                            <span class="flex gap-1 text-xs font-bold">
                                <a href="{{ route('dash.tables.print', ['table' => $t->id]) }}" target="_blank" class="flex min-h-10 items-center rounded-lg bg-brand-50 px-2.5 text-brand-700 hover:bg-brand-100">QR</a>
                                <a href="{{ route('dash.tables.svg', $t) }}" class="flex min-h-10 items-center rounded-lg bg-navy-50 px-2.5 text-navy-600 hover:bg-navy-100" title="{{ __('Download SVG') }}">SVG</a>
                            </span>
                        </div>
                        <details>
                            <summary class="flex min-h-10 cursor-pointer items-center text-sm font-semibold text-navy-500 hover:text-navy-800">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.tables.update', $t) }}" class="mt-2 space-y-2">
                                @csrf
                                <input class="field" name="name" value="{{ $t->name }}" required maxlength="40">
                                <input class="field" name="seats" type="number" min="1" max="50" value="{{ $t->seats }}" placeholder="{{ __('Seats') }}">
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-primary min-h-9 w-full">{{ __('Save') }}</button>
                            </form>
                            <div class="mt-2 flex gap-2">
                                <form method="post" action="{{ route('dash.tables.rotate', $t) }}" onsubmit="return confirm(@js(__('The old QR of this table will stop working. Continue?')))">@csrf<button class="min-h-10 px-1 text-xs text-navy-500 underline">{{ __('New QR') }}</button></form>
                                <form method="post" action="{{ route('dash.tables.delete', $t) }}" onsubmit="return confirm(@js(__('Delete this table?')))">@csrf<button class="min-h-10 px-1 text-xs text-red-600 underline">{{ __('Delete') }}</button></form>
                            </div>
                        </details>
                    </div>
                @empty
                    <p class="empty-state col-span-full py-6">{{ __('No tables yet.') }}</p>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
@endsection
