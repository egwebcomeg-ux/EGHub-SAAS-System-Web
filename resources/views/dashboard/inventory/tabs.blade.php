{{-- Inventory sub-navigation + branch picker (hidden for staff tied to one branch). --}}
@php
    $inventoryTabs = [
        ['dash.inventory', __('Stock')],
        ['dash.inventory.counts', __('Stock counts')],
        ['dash.inventory.purchases', __('Purchases')],
        ['dash.inventory.suppliers', __('Suppliers')],
        ['dash.inventory.movements', __('Movements')],
        ['dash.inventory.variance', __('Variance')],
        ['dash.inventory.recipes', __('Recipes & cost')],
    ];
    $currentTab = request()->routeIs('dash.inventory.recipe') ? 'dash.inventory.recipes'
        : (collect($inventoryTabs)->skip(1)->first(fn ($t) => request()->routeIs($t[0], $t[0] . '.*'))[0] ?? 'dash.inventory');
@endphp
@include('dashboard.partials.tabs', [
    'label' => __('Inventory'), 'current' => $currentTab,
    'tabs' => array_map(fn ($t) => [$t[0], $t[1], route($t[0])], $inventoryTabs),
])
@if (isset($branches) && $branches->count() > 1)
    <form class="mb-4 flex justify-end">
        @foreach (request()->only(['from', 'to', 'kind']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <select name="branch" class="field min-h-9 w-auto py-0" onchange="this.form.submit()" aria-label="{{ __('Branch') }}">
            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach
        </select>
    </form>
@endif
