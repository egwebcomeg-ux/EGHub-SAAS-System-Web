@extends('layouts.dashboard')
@section('title', $purchase->exists ? __('Purchase') . ' #' . $purchase->id : __('New purchase'))

@section('content')
@php
    $editable = ! $purchase->exists || $purchase->isDraft();
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
@endphp
<x-dash.page-header :title="$purchase->exists ? __('Purchase') . ' #' . $purchase->id . ' · ' . $purchase->statusLabel() : __('New purchase')"
    :help="$editable ? __('Save it as a draft while you check the goods, then post it to add them to the stock.') : null">
    <x-slot:actions>
        <a href="{{ route('dash.inventory.purchases') }}" class="btn-ghost">{{ __('Back') }}</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.inventory.tabs')

@if ($editable && $suppliers->isEmpty())
    <div class="card text-navy-600">{{ __('Add a supplier first.') }} <a href="{{ route('dash.inventory.suppliers') }}" class="text-brand-700 underline">{{ __('Suppliers') }}</a></div>
@elseif ($editable)
    @php
        $rows = old('lines', $purchase->exists ? $purchase->lines->map(fn ($l) => ['ingredient_id' => $l->ingredient_id, 'quantity' => (float) $l->quantity, 'unit_cost' => (float) $l->unit_cost])->all() : []);
        $rows = array_merge(array_values($rows), array_fill(0, max(0, min($max - count($rows), 5)), ['ingredient_id' => null, 'quantity' => '', 'unit_cost' => '']));
    @endphp
    <form method="post" action="{{ $purchase->exists ? route('dash.inventory.purchases.update', $purchase) : route('dash.inventory.purchases.store') }}" class="card space-y-3" data-purchase-form>
        @csrf
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="field-label">{{ __('Supplier') }}</label>
                <select class="field" name="supplier_id" required>
                    <option value="">—</option>
                    @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id', $purchase->supplier_id) === $s->id)>{{ $s->name }}</option>@endforeach
                </select>
            </div>
            @if ($branches->count() > 1)
                <div>
                    <label class="field-label">{{ __('Branch') }}</label>
                    <select class="field" name="branch_id">
                        @foreach ($branches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $purchase->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="field-label">{{ __('Date') }}</label>
                <input class="field" name="purchased_on" type="date" required value="{{ old('purchased_on', $purchase->purchased_on?->format('Y-m-d')) }}">
            </div>
            <div>
                <label class="field-label">{{ __('Invoice number') }}</label>
                <input class="field" name="reference" maxlength="60" value="{{ old('reference', $purchase->reference) }}">
            </div>
        </div>
        <table class="table-base">
            <thead><tr><th>{{ __('Ingredient') }}</th><th class="w-32">{{ __('Quantity') }}</th><th class="w-32">{{ __('Unit cost') }}</th></tr></thead>
            <tbody>
            @foreach ($rows as $n => $row)
                <tr>
                    <td>
                        <select class="field min-h-9" name="lines[{{ $n }}][ingredient_id]">
                            <option value="">—</option>
                            @foreach ($ingredients->groupBy('kind') as $kind => $group)
                                <optgroup label="{{ $group->first()->kindLabel() }}">
                                    @foreach ($group as $i)<option value="{{ $i->id }}" @selected((int) ($row['ingredient_id'] ?? 0) === $i->id)>{{ $i->name }} ({{ $i->unitLabel() }})</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </td>
                    <td><input class="field min-h-9" name="lines[{{ $n }}][quantity]" type="number" step="0.001" min="0" value="{{ $row['quantity'] ?? '' }}"></td>
                    <td><input class="field min-h-9" name="lines[{{ $n }}][unit_cost]" type="number" step="0.0001" min="0" value="{{ $row['unit_cost'] ?? '' }}"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="text-xs text-navy-500">{{ __('Need more rows? Save, and new empty rows appear.') }}</p>
        <input class="field" name="note" maxlength="200" value="{{ old('note', $purchase->note) }}" placeholder="{{ __('Note') }}">
        @if ($purchase->exists)<p class="text-sm">{{ __('Total') }}: <b>{{ money($purchase->total) }}</b></p>@endif
        <div class="flex flex-wrap gap-2">
            <button class="btn-ghost">{{ __('Save draft') }}</button>
            <button class="btn-primary" name="post" value="1" data-purchase-post onclick="return confirm(@js(__('Post this purchase? Its goods are added to the stock.')))">{{ __('Save and post') }}</button>
        </div>
    </form>
    @if ($purchase->exists)
        <form method="post" action="{{ route('dash.inventory.purchases.destroy', $purchase) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft purchase?')))">
            @csrf
            <button class="btn-ghost min-h-9 px-3 text-sm text-red-600">{{ __('Delete draft') }}</button>
        </form>
    @endif
@else
    <section class="card space-y-3 overflow-x-auto">
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span>{{ __('Supplier') }}: <a href="{{ route('dash.inventory.suppliers.statement', $purchase->supplier_id) }}" class="font-bold text-brand-700 underline">{{ $purchase->supplier?->name }}</a></span>
            <span>{{ __('Branch') }}: <b>{{ $purchase->branch ? tr($purchase->branch->name, $purchase->branch->name_en) : '—' }}</b></span>
            <span>{{ __('Date') }}: <b>{{ $purchase->purchased_on->format('Y/m/d') }}</b></span>
            @if ($purchase->reference)<span>{{ __('Invoice number') }}: <b>{{ $purchase->reference }}</b></span>@endif
        </div>
        <table class="table-base">
            <thead><tr><th>{{ __('Ingredient') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit cost') }}</th><th>{{ __('Total') }}</th></tr></thead>
            <tbody>
            @foreach ($purchase->lines as $line)
                <tr>
                    <td class="font-semibold">{{ $line->ingredient?->name }}</td>
                    <td dir="ltr" class="text-end">{{ $qty($line->quantity) }} {{ $line->ingredient?->unitLabel() }}</td>
                    <td dir="ltr" class="text-end">{{ $qty($line->unit_cost) }}</td>
                    <td>{{ money($line->total) }}</td>
                </tr>
            @endforeach
            <tr class="font-bold"><td colspan="3">{{ __('Total') }}</td><td>{{ money($purchase->total) }}</td></tr>
            </tbody>
        </table>
        @if ($purchase->note)<p class="text-sm text-navy-600">{{ $purchase->note }}</p>@endif
        <p class="text-sm text-navy-500">{{ __('Posted by :name on :date', ['name' => $purchase->poster?->name ?? '—', 'date' => local_time($purchase->posted_at)]) }}</p>
        @if ($purchase->cancelled_at)
            <p class="text-sm text-red-600">{{ __('Cancelled by :name: :reason', ['name' => $purchase->canceller?->name ?? '—', 'reason' => $purchase->cancel_reason]) }}</p>
        @elseif ($isManager)
            <form method="post" action="{{ route('dash.inventory.purchases.cancel', $purchase) }}" data-purchase-cancel
                  onsubmit="const r = prompt(@js(__('Why is this purchase cancelled? Its goods will be taken out of the stock.'))); if (!r) return false; this.reason.value = r;">
                @csrf <input type="hidden" name="reason">
                <button class="btn-ghost min-h-9 px-3 text-sm text-red-600">{{ __('Cancel purchase (manager)') }}</button>
            </form>
        @endif
    </section>
@endif
@endsection
