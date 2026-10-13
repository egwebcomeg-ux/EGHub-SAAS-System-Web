@extends('layouts.dashboard')
@section('title', __('Recipe') . ' · ' . $item->name)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Recipe') }}: {{ $item->name }}</h1>
        <p class="muted">{{ __('What one portion uses. Lines tied to an option (e.g. Large) are taken only when the guest picks it.') }}</p>
    </div>
    <a href="{{ route('dash.inventory.recipes') }}" class="btn-ghost">{{ __('Back') }}</a>
</div>

@if ($ingredients->isEmpty())
    <div class="card text-navy-600">{{ __('Add ingredients first from the Stock page.') }} <a href="{{ route('dash.inventory') }}" class="text-brand-700 underline">{{ __('Stock') }}</a></div>
@else
    @php
        $options = $item->optionGroups->flatMap(fn ($g) => $g->options->map(fn ($o) => ['id' => $o->id, 'label' => $g->name . ': ' . $o->name]));
        $rows = $lines->map(fn ($l) => ['ingredient_id' => $l->ingredient_id, 'quantity' => (float) $l->quantity, 'option_id' => $l->item_option_id])->all();
        $rows = array_merge($rows, array_fill(0, max(0, min($max - count($rows), 5)), ['ingredient_id' => null, 'quantity' => '', 'option_id' => null]));
    @endphp
    <form method="post" action="{{ route('dash.inventory.recipe.save', $item) }}" class="card space-y-3">
        @csrf
        <div class="flex flex-wrap gap-4 text-sm">
            <span>{{ __('Price') }}: <b>{{ money($item->price) }}</b></span>
            <span>{{ __('Ingredient cost') }}: <b>{{ money($cost) }}</b></span>
            @if ($item->price > 0 && $cost > 0)<span>{{ __('Food cost') }}: <b dir="ltr">{{ round($cost / $item->price * 100) }}%</b></span>@endif
        </div>
        <table class="table-base">
            <thead><tr><th>{{ __('Ingredient') }}</th><th class="w-36">{{ __('Quantity') }}</th>@if ($options->isNotEmpty())<th>{{ __('Only with option') }}</th>@endif</tr></thead>
            <tbody>
            @foreach ($rows as $n => $row)
                <tr>
                    <td>
                        <select class="field min-h-9" name="lines[{{ $n }}][ingredient_id]">
                            <option value="">—</option>
                            @foreach ($ingredients as $i)<option value="{{ $i->id }}" @selected($row['ingredient_id'] === $i->id)>{{ $i->name }} ({{ $i->unitLabel() }})</option>@endforeach
                        </select>
                    </td>
                    <td><input class="field min-h-9" name="lines[{{ $n }}][quantity]" type="number" step="0.001" min="0" value="{{ $row['quantity'] }}"></td>
                    @if ($options->isNotEmpty())
                        <td>
                            <select class="field min-h-9" name="lines[{{ $n }}][option_id]">
                                <option value="">{{ __('Always') }}</option>
                                @foreach ($options as $o)<option value="{{ $o['id'] }}" @selected($row['option_id'] === $o['id'])>{{ $o['label'] }}</option>@endforeach
                            </select>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="text-xs text-navy-500">{{ __('Need more rows? Save, and new empty rows appear.') }}</p>
        <button class="btn-primary">{{ __('Save recipe') }}</button>
    </form>
@endif
@endsection
