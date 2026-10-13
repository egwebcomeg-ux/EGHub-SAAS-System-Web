<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Delivery areas per branch: fee, minimum order and estimated minutes; sub-areas inherit empty values. */
class DeliveryZonesController extends Controller
{
    public function index(): View
    {
        return view('dashboard.delivery', [
            'branches' => Branch::query()->orderBy('sort_order')->get(),
            // "Main › Sub" labels sort each sub-area right under its main area
            'zones' => DeliveryZone::query()->with('parent')->get()->sortBy(fn (DeliveryZone $z) => $z->label())->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DeliveryZone::query()->create($this->validated($request) + ['sort_order' => (int) DeliveryZone::query()->max('sort_order') + 1]);
        return back()->with('ok', __('Zone saved.'));
    }

    public function update(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $zone->update($this->validated($request, $zone) + ['is_active' => $request->boolean('is_active')]);
        return back()->with('ok', __('Zone saved.'));
    }

    public function destroy(DeliveryZone $zone): RedirectResponse
    {
        $zone->delete();
        return back()->with('ok', __('Zone deleted.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?DeliveryZone $zone = null): array
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'parent_id' => ['nullable', Rule::exists('delivery_zones', 'id')->where('tenant_id', $tenant)->whereNull('parent_id'), Rule::notIn([$zone?->id])],
            'name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80',
            'fee' => 'nullable|numeric|min:0|max:10000', 'min_order' => 'nullable|numeric|min:0|max:100000',
            'estimated_minutes' => 'nullable|integer|min:5|max:300',
        ]);
        return $data + ['name_en' => ''];
    }
}
