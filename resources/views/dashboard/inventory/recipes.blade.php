@extends('layouts.dashboard')
@section('title', __('Recipes & cost'))

@section('content')
<x-dash.page-header :title="__('Recipes & cost')" :help="__('Food cost is the ingredient cost of one plain portion compared with its menu price. Around 25–35% is usual for cafes.')" />
@include('dashboard.inventory.tabs')

@foreach ($categories as $c)
    @continue($c->items->isEmpty())
    <section class="card mb-4 overflow-x-auto">
        <h2 class="section-title mb-2">{{ $c->name }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Price') }}</th><th>{{ __('Ingredient cost') }}</th><th>{{ __('Food cost') }}</th><th>{{ __('Profit per portion') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($c->items as $item)
                @php
                    $cost = isset($costs[$item->id]) ? round((float) $costs[$item->id], 2) : null;
                    $pct = $cost !== null && $item->price > 0 ? round($cost / $item->price * 100) : null;
                @endphp
                <tr>
                    <td class="font-semibold">{{ $item->name }}</td>
                    <td>{{ money($item->price) }}</td>
                    <td>{{ $cost !== null ? money($cost) : '—' }}</td>
                    <td>
                        @if ($pct !== null)
                            <span dir="ltr" @class(['badge', 'bg-emerald-100 text-emerald-800' => $pct <= 35, 'bg-amber-100 text-amber-800' => $pct > 35 && $pct <= 45, 'bg-brand-100 text-brand-700' => $pct > 45])>{{ $pct }}%</span>
                        @else
                            <span class="text-xs text-navy-400">{{ __('No recipe') }}</span>
                        @endif
                    </td>
                    <td>{{ $cost !== null ? money($item->price - $cost) : '—' }}</td>
                    <td><a href="{{ route('dash.inventory.recipe', $item) }}" class="btn-ghost min-h-9 px-3">{{ $cost !== null ? __('Edit recipe') : __('Add recipe') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endforeach
@endsection
