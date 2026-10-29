@extends('layouts.dashboard')
@section('title', __('Suppliers'))

@section('content')
<x-dash.page-header :title="__('Suppliers')" :help="__('Who you buy ingredients and packaging from. Open a supplier for the statement of everything bought from them.')" />
@include('dashboard.inventory.tabs')

<div class="grid gap-4 xl:grid-cols-3">
    <section class="card overflow-x-auto xl:col-span-2">
        <table class="table-base">
            <thead><tr><th>{{ __('Supplier') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Purchased') }}</th><th>{{ __('Last purchase') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($suppliers as $s)
                <tr @class(['opacity-50' => ! $s->is_active])>
                    <td class="font-semibold"><a href="{{ route('dash.inventory.suppliers.statement', $s) }}" class="text-brand-700 underline">{{ $s->name }}</a>@if ($s->notes)<div class="text-xs font-normal text-navy-500">{{ $s->notes }}</div>@endif</td>
                    <td dir="ltr" class="text-navy-500">{{ $s->phone ?? '—' }}</td>
                    <td>{{ money($s->purchased ?? 0) }}</td>
                    <td class="text-navy-500">{{ $s->last_purchase ? \Illuminate\Support\Carbon::parse($s->last_purchase)->format('Y/m/d') : '—' }}</td>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('dash.inventory.purchases.create', ['supplier' => $s->id]) }}" class="btn-ghost min-h-9 px-3">{{ __('New purchase') }}</a>
                        <details class="relative inline-block">
                            <summary class="btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.inventory.suppliers.update', $s) }}" class="card absolute end-0 z-10 mt-2 w-72 space-y-2">
                                @csrf
                                @include('dashboard.inventory.supplier-fields', ['s' => $s])
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($s->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-primary w-full">{{ __('Save') }}</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-navy-500">{{ __('Add your first supplier, e.g. the dairy or the packaging shop.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <form method="post" action="{{ route('dash.inventory.suppliers.store') }}" class="card space-y-2">
        @csrf
        <h2 class="section-title">{{ __('Add supplier') }}</h2>
        @include('dashboard.inventory.supplier-fields', ['s' => null])
        <button class="btn-primary w-full">{{ __('Add supplier') }}</button>
    </form>
</div>
@endsection
