@extends('layouts.dashboard')
@section('title', __('Recipes & cost'))

@section('content')
<x-dash.page-header :title="__('Recipes & cost')" :help="__('Food cost is the ingredient cost of one plain portion compared with its menu price. Around 25–35% is usual for cafes.')" />
@include('dashboard.inventory.tabs')

<div class="mb-4 grid gap-4 lg:grid-cols-2">
    <form method="post" action="{{ route('dash.inventory.food-cost-target') }}" class="card flex flex-wrap items-end gap-3" data-food-cost-target>
        @csrf
        <div>
            <label class="field-label">{{ __('Flag items with food cost above (%)') }}</label>
            <input class="field w-28" name="food_cost_target" type="number" step="0.1" min="1" max="100" value="{{ rtrim(rtrim(number_format($target, 1, '.', ''), '0'), '.') }}" required>
        </div>
        <button class="btn-primary">{{ __('Save') }}</button>
        <div class="text-sm">
            @if ($aboveCount > 0)
                <span class="badge bg-brand-100 text-brand-700" data-above-count>{{ __(':n items above the target', ['n' => $aboveCount]) }}</span>
                <a href="{{ route('dash.inventory.recipes', $onlyAbove ? [] : ['above' => 1]) }}" class="ms-2 text-brand-700 underline">{{ $onlyAbove ? __('Show all') : __('Show only these') }}</a>
            @else
                <span class="text-navy-500">{{ __('No item is above the target.') }}</span>
            @endif
        </div>
    </form>

    <section class="card space-y-2" data-order-packaging>
        <h2 class="section-title">{{ __('Packaging per order') }}</h2>
        <p class="text-xs text-navy-500">{{ __('Taken once from the stock with every takeaway or delivery order, e.g. 1 bag per takeaway order. Packaging of one item (its cup and lid) goes in its recipe.') }}</p>
        @if ($rules->isNotEmpty())
            <table class="table-base text-sm">
                <tbody>
                @foreach ($rules as $rule)
                    <tr>
                        <td>{{ $rule->orderTypeLabel() }}</td>
                        <td class="font-semibold">{{ $rule->ingredient?->name }}</td>
                        <td dir="ltr" class="text-end">{{ rtrim(rtrim(number_format($rule->quantity, 3, '.', ''), '0'), '.') }} {{ $rule->ingredient?->unitLabel() }}</td>
                        <td>
                            <form method="post" action="{{ route('dash.inventory.packaging.destroy', $rule) }}">
                                @csrf
                                <button class="btn-ghost min-h-8 px-2 text-xs text-red-600">{{ __('Remove') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach (\App\Models\PackagingRule::ORDER_TYPES as $type => $label)
                    <span>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}: <b>{{ money($orderPackaging[$type] ?? 0) }}</b></span>
                @endforeach
            </div>
        @endif
        @if ($packaging->isNotEmpty())
            <form method="post" action="{{ route('dash.inventory.packaging.store') }}" class="flex flex-wrap gap-2">
                @csrf
                <select class="field min-h-9 w-auto" name="order_type" required>
                    @foreach (\App\Models\PackagingRule::ORDER_TYPES as $type => $label)<option value="{{ $type }}">{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>@endforeach
                </select>
                <select class="field min-h-9 w-auto" name="ingredient_id" required>
                    @foreach ($packaging as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unitLabel() }})</option>@endforeach
                </select>
                <input class="field min-h-9 w-24" name="quantity" type="number" step="0.001" min="0.001" value="1" required aria-label="{{ __('Quantity') }}">
                <button class="btn-primary min-h-9">{{ __('Add') }}</button>
            </form>
        @endif
    </section>
</div>

@foreach ($categories as $c)
    @php $items = $onlyAbove ? $c->items->filter($isAbove) : $c->items; @endphp
    @continue($items->isEmpty())
    <section class="card mb-4 overflow-x-auto">
        <h2 class="section-title mb-2">{{ $c->name }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Price') }}</th><th>{{ __('Ingredient cost') }}</th><th>{{ __('Food cost') }}</th><th>{{ __('With takeaway packaging') }}</th><th>{{ __('Profit per portion') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($items as $item)
                @php
                    $cost = isset($costs[$item->id]) ? round((float) $costs[$item->id], 2) : null;
                    $takeaway = isset($takeawayCosts[$item->id]) ? round((float) $takeawayCosts[$item->id], 2) : null;
                    $pct = $cost !== null && $item->price > 0 ? round($cost / $item->price * 100) : null;
                    $takeawayPct = $takeaway !== null && $item->price > 0 ? round($takeaway / $item->price * 100) : null;
                    $above = $isAbove($item);
                @endphp
                <tr @if ($above) data-above-target @endif>
                    <td class="font-semibold">{{ $item->name }}</td>
                    <td>{{ money($item->price) }}</td>
                    <td>{{ $cost !== null ? money($cost) : '—' }}</td>
                    <td>
                        @if ($pct !== null)
                            <span dir="ltr" @class(['badge', 'bg-emerald-100 text-emerald-800' => ! $above, 'bg-brand-100 text-brand-700' => $above])>{{ $pct }}%</span>
                            @if ($above)<span class="text-xs font-bold text-brand-700">{{ __('Above target') }}</span>@endif
                        @else
                            <span class="text-xs text-navy-400">{{ __('No recipe') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($takeaway !== null && $takeaway !== $cost)
                            {{ money($takeaway) }} @if ($takeawayPct !== null)<span dir="ltr" @class(['text-xs', 'font-bold text-brand-700' => $takeawayPct > $target, 'text-navy-500' => $takeawayPct <= $target])>{{ $takeawayPct }}%</span>@endif
                        @else
                            <span class="text-navy-400">—</span>
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
