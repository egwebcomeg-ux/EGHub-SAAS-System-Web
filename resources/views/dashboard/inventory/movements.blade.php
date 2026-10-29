@extends('layouts.dashboard')
@section('title', __('Stock movements'))

@section('content')
<x-dash.page-header :title="__('Stock movements')" />
@include('dashboard.inventory.tabs')

<form class="mb-3 flex flex-wrap gap-2">
    <select name="type" class="field min-h-9 w-auto py-0" onchange="this.form.submit()">
        <option value="">{{ __('All types') }}</option>
        @foreach (\App\Models\StockMovement::TYPES as $key => $label)
            <option value="{{ $key }}" @selected(request('type') === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>
        @endforeach
    </select>
    <select name="kind" class="field min-h-9 w-auto py-0" onchange="this.form.submit()" aria-label="{{ __('Kind') }}">
        <option value="">{{ __('Ingredients and packaging') }}</option>
        @foreach (\App\Models\Ingredient::KINDS as $key => $label)
            <option value="{{ $key }}" @selected(request('kind') === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>
        @endforeach
    </select>
    <select name="ingredient" class="field min-h-9 w-auto py-0" onchange="this.form.submit()">
        <option value="">{{ __('All ingredients') }}</option>
        @foreach ($ingredients as $i)<option value="{{ $i->id }}" @selected((int) request('ingredient') === $i->id)>{{ $i->name }}</option>@endforeach
    </select>
</form>

<section class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Ingredient') }}</th><th>{{ __('Type') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('By') }}</th><th>{{ __('Note') }}</th></tr></thead>
        <tbody>
        @forelse ($movements as $m)
            <tr>
                <td class="whitespace-nowrap text-navy-500">{{ local_time($m->created_at, 'd/m h:i A') }}</td>
                <td class="font-semibold">{{ $m->ingredient?->name }}</td>
                <td>
                    {{ $m->typeLabel() }}@if ($m->order) <span class="text-xs text-navy-500">#{{ $m->order->number }}</span>@endif
                    @if ($m->purchase) <a href="{{ route('dash.inventory.purchases.show', $m->purchase) }}" class="text-xs text-brand-700 underline">{{ __('Purchase') }} #{{ $m->purchase->id }}</a>@endif
                    @if ($m->stockCount) <a href="{{ route('dash.inventory.counts.show', $m->stockCount) }}" class="text-xs text-brand-700 underline">{{ __('Count sheet') }} #{{ $m->stockCount->id }}</a>@endif
                </td>
                <td dir="ltr" @class(['text-end font-bold', 'text-emerald-700' => $m->quantity > 0, 'text-navy-700' => $m->quantity < 0])>{{ $m->quantity > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($m->quantity, 3, '.', ''), '0'), '.') }} {{ $m->ingredient?->unitLabel() }}</td>
                <td class="text-navy-500">{{ $m->user?->name ?? '—' }}</td>
                <td class="text-navy-500">{{ $m->note }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-navy-500">{{ __('No movements yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-3">{{ $movements->links() }}</div>
</section>
@endsection
