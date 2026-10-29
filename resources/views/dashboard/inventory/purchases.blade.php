@extends('layouts.dashboard')
@section('title', __('Purchases'))

@section('content')
<x-dash.page-header :title="__('Purchases')" :help="__('Type the supplier invoice, then post it: the goods are added to the branch stock and the average cost is updated.')">
    <x-slot:actions>
        <a href="{{ route('dash.inventory.purchases.create') }}" class="btn-primary">{{ __('New purchase') }}</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.inventory.tabs')

<form class="mb-3 flex flex-wrap gap-2">
    <select name="supplier" class="field min-h-9 w-auto py-0" onchange="this.form.submit()" aria-label="{{ __('Supplier') }}">
        <option value="">{{ __('All suppliers') }}</option>
        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier') === $s->id)>{{ $s->name }}</option>@endforeach
    </select>
    <select name="status" class="field min-h-9 w-auto py-0" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
        <option value="">{{ __('All') }}</option>
        @foreach (\App\Models\Purchase::STATUSES as $key => $label)
            <option value="{{ $key }}" @selected(request('status') === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>
        @endforeach
    </select>
</form>

<section class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Lines') }}</th><th>{{ __('Total') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($purchases as $p)
            <tr @class(['line-through opacity-60' => $p->status === 'cancelled']) data-purchase-row="{{ $p->id }}">
                <td class="whitespace-nowrap">{{ $p->purchased_on->format('Y/m/d') }}</td>
                <td class="font-semibold">{{ $p->supplier?->name }}</td>
                <td class="text-navy-500">{{ $p->reference ?? '—' }}</td>
                <td class="text-navy-500">{{ $p->branch ? tr($p->branch->name, $p->branch->name_en) : '—' }}</td>
                <td class="text-navy-500">{{ $p->lines_count }}</td>
                <td class="font-bold">{{ money($p->total) }}</td>
                <td><span @class(['badge', 'bg-amber-100 text-amber-800' => $p->isDraft(), 'bg-emerald-100 text-emerald-800' => $p->isPosted(), 'bg-navy-100 text-navy-600' => $p->status === 'cancelled'])>{{ $p->statusLabel() }}</span></td>
                <td><a href="{{ route('dash.inventory.purchases.show', $p) }}" class="btn-ghost min-h-9 px-3">{{ $p->isDraft() ? __('Edit') : __('View') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-center text-navy-500">{{ __('No purchases yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-3">{{ $purchases->links() }}</div>
</section>
@endsection
