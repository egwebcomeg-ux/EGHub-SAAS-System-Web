@extends('layouts.dashboard')
@section('title', __('Tables & QR'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('Tables & QR') }}</h1>
        <p class="text-sm text-navy-500">{{ __('Tables') }}: {{ $usage['used'] }} / {{ $usage['limit'] ?? '∞' }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if (\App\Services\Entitlements::current()->allows('customer_display'))
            <a href="{{ tenant()->url('board') }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Order-number board (hall screen)') }}</a>
        @endif
        <a href="{{ route('dash.tables.print', ['menu' => 1]) }}" target="_blank" class="btn-ghost">{{ __('Print general menu QR') }}</a>
        <a href="{{ route('dash.tables.print') }}" target="_blank" class="btn-primary">{{ __('Print all table QR cards') }}</a>
    </div>
</div>

<div class="space-y-4">
    @foreach ($branches as $branch)
        <section class="card">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">{{ tr($branch->name, $branch->name_en) }}</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="post" action="{{ route('dash.tables.store') }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                        <span class="text-sm">{{ __('Add tables from') }}</span>
                        <input class="field w-20" name="from" type="number" min="1" max="999" value="{{ $branch->tables->count() + 1 }}" required>
                        <span class="text-sm">{{ __('to') }}</span>
                        <input class="field w-20" name="to" type="number" min="1" max="999" value="{{ $branch->tables->count() + 1 }}" required>
                        <button class="btn-ghost">{{ __('Add') }}</button>
                    </form>
                    <a href="{{ route('dash.tables.print', ['branch' => $branch->id]) }}" target="_blank" class="btn-ghost">{{ __('Print') }}</a>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($branch->tables as $t)
                    <div @class(['rounded-xl border p-3', 'border-navy-100' => $t->is_active, 'border-dashed border-navy-200 opacity-60' => !$t->is_active])>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-lg font-extrabold">{{ __('Table :name', ['name' => $t->name]) }}</span>
                            <a href="{{ route('dash.tables.print', ['table' => $t->id]) }}" target="_blank" class="text-sm text-brand-700">QR</a>
                        </div>
                        <details>
                            <summary class="cursor-pointer text-sm text-navy-500">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.tables.update', $t) }}" class="mt-2 space-y-2">
                                @csrf
                                <input class="field" name="name" value="{{ $t->name }}" required maxlength="40">
                                <input class="field" name="seats" type="number" min="1" max="50" value="{{ $t->seats }}" placeholder="{{ __('Seats') }}">
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-primary min-h-9 w-full">{{ __('Save') }}</button>
                            </form>
                            <div class="mt-2 flex gap-2">
                                <form method="post" action="{{ route('dash.tables.rotate', $t) }}" onsubmit="return confirm(@js(__('The old QR of this table will stop working. Continue?')))">@csrf<button class="text-xs text-navy-500 underline">{{ __('New QR') }}</button></form>
                                <form method="post" action="{{ route('dash.tables.delete', $t) }}" onsubmit="return confirm(@js(__('Delete this table?')))">@csrf<button class="text-xs text-red-600 underline">{{ __('Delete') }}</button></form>
                            </div>
                        </details>
                    </div>
                @empty
                    <p class="text-sm text-navy-500">{{ __('No tables yet.') }}</p>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
@endsection
