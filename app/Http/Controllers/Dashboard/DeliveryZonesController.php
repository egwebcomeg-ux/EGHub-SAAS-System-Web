<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Delivery areas per branch: fee, minimum order and estimated minutes; sub-areas inherit empty values. */
class DeliveryZonesController extends Controller
{
    public function index(): View
    {
        $branch = Branch::query()->whereNotNull('lat')->first();

        return view('dashboard.delivery', [
            'mode' => Settings::get('delivery_zone_mode') === 'map' ? 'map' : 'manual',
            'center' => $branch ? [(float) $branch->lat, (float) $branch->lng] : null,
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

    /** How the restaurant sets its areas: typed by hand (main area → streets) or drawn on the map. */
    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['manual', 'map'])]]);
        Settings::put(['delivery_zone_mode' => $data['mode']]);

        return back()->with('ok', $data['mode'] === 'map' ? __('Map drawing is on.') : __('Manual areas are on.'));
    }

    /**
     * How a branch prices delivery: by its zones, or by the straight-line distance from its pin with rings
     * ("up to 2 km: 25"). Distance needs the pin and at least one ring; the last ring is the farthest delivered.
     */
    public function pricing(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([DeliveryPricing::ZONES, DeliveryPricing::DISTANCE])],
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng|required_if:method,distance',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat|required_if:method,distance',
            'rings' => 'array|max:'.DeliveryPricing::MAX_RINGS.'|required_if:method,distance',
            'rings.*.km' => 'required|numeric|min:0.1|max:'.DeliveryPricing::MAX_KM.'|distinct',
            'rings.*.fee' => 'required|numeric|min:0|max:10000',
            'rings.*.min' => 'nullable|numeric|min:0|max:100000',
            'rings.*.minutes' => 'nullable|integer|min:5|max:300',
        ], [], ['rings.*.km' => __('distance'), 'rings.*.fee' => __('delivery fee')]);

        $rings = collect($data['rings'] ?? [])->map(fn (array $r) => [
            'km' => round((float) $r['km'], 2), 'fee' => round((float) $r['fee'], 2),
            'min' => round((float) ($r['min'] ?? 0), 2), 'minutes' => isset($r['minutes']) && $r['minutes'] !== '' ? (int) $r['minutes'] : null,
        ])->sortBy('km')->values()->all();
        // back to zones keeps the rings, so switching to distance again finds them
        $branch->update(['delivery_pricing' => $data['method']]
            + ($data['method'] === DeliveryPricing::DISTANCE ? ['delivery_rings' => $rings] : [])
            + (isset($data['lat'], $data['lng']) ? ['lat' => round((float) $data['lat'], 7), 'lng' => round((float) $data['lng'], 7)] : []));

        return back()->with('ok', $data['method'] === DeliveryPricing::DISTANCE ? __('Delivery is priced by distance now.') : __('Delivery is priced by your zones now.'));
    }

    /** Saves the area drawn on the map: [[lat, lng], ...], or clears it. */
    public function polygon(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $request->validate([
            'polygon' => 'nullable|array|min:3|max:300',
            'polygon.*' => 'array|size:2',
            'polygon.*.0' => 'numeric|between:-90,90',
            'polygon.*.1' => 'numeric|between:-180,180',
        ]);
        $points = collect($request->input('polygon', []))
            ->map(fn (array $p): array => [round((float) $p[0], 6), round((float) $p[1], 6)])->values()->all();
        $zone->update(['polygon' => $points ?: null]);

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
