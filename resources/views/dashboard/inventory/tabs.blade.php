{{-- Inventory sub-navigation + branch picker (hidden for staff tied to one branch). --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap gap-2">
        @foreach ([['dash.inventory', __('Stock')], ['dash.inventory.movements', __('Movements')], ['dash.inventory.recipes', __('Recipes & cost')]] as [$r, $label])
            <a href="{{ route($r) }}" @class(['btn min-h-9 rounded-full px-4', 'bg-navy-800 text-white' => request()->routeIs($r), 'bg-white text-navy-700 border border-navy-100' => !request()->routeIs($r)])>{{ $label }}</a>
        @endforeach
    </div>
    @if (isset($branches) && $branches->count() > 1)
        <form>
            <select name="branch" class="field min-h-9 py-0" onchange="this.form.submit()">
                @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach
            </select>
        </form>
    @endif
</div>
