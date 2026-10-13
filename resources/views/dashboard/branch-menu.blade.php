@extends('layouts.dashboard')
@section('title', __('Branch menu') . ' · ' . $branch->name)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Branch menu') }}: {{ $branch->name }}</h1>
        <p class="muted">{{ __('Leave the price empty to use the main menu price. Untick "Sold here" to hide an item in this branch only.') }}</p>
    </div>
    <a href="{{ route('dash.branches') }}" class="btn-ghost">{{ __('Back') }}</a>
</div>

<form method="post" action="{{ route('dash.branches.menu.update', $branch) }}" class="space-y-4">
    @csrf
    @foreach ($categories as $c)
        @continue($c->items->isEmpty())
        <section class="card overflow-x-auto">
            <h2 class="section-title mb-2">{{ $c->name }}</h2>
            <table class="table-base">
                <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Main price') }}</th><th class="w-36">{{ __('Price in this branch') }}</th><th>{{ __('Sold here') }}</th><th>{{ __('Sold out here') }}</th></tr></thead>
                <tbody>
                @foreach ($c->items as $item)
                    @php
                        $o = $overrides[$item->id] ?? null;
                    @endphp
                    <tr @class(['bg-brand-50/50' => $o])>
                        <td class="font-semibold">{{ $item->name }}</td>
                        <td class="text-navy-500">{{ money($item->price) }}</td>
                        <td><input class="field min-h-9" name="items[{{ $item->id }}][price]" type="number" step="0.5" min="0" value="{{ $o?->price !== null ? (float) $o->price : '' }}" placeholder="{{ money($item->price) }}"></td>
                        <td><input type="checkbox" name="items[{{ $item->id }}][available]" value="1" @checked(!$o || $o->is_available) class="size-5 accent-brand-600"></td>
                        <td><input type="checkbox" name="items[{{ $item->id }}][sold_out]" value="1" @checked($o?->is_sold_out) class="size-5 accent-brand-600"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
    <button class="btn-primary">{{ __('Save branch menu') }}</button>
</form>
@endsection
