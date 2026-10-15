<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\KitchenStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kitchen stations (grill, drinks, desserts) and which categories each one prepares. */
class StationsController extends Controller
{
    public function index(): View
    {
        return view('dashboard.stations', [
            'stations' => KitchenStation::query()->orderBy('sort_order')->get(),
            'categories' => Category::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'name_en' => 'nullable|string|max:60']);
        KitchenStation::query()->create(['name' => $data['name'], 'name_en' => $data['name_en'] ?? '', 'sort_order' => (int) KitchenStation::query()->max('sort_order') + 1]);

        return back()->with('ok', __('Station added.'));
    }

    public function destroy(KitchenStation $station): RedirectResponse
    {
        $station->delete(); // categories fall back to "all screens" (FK null on delete)

        return back()->with('ok', __('Station deleted.'));
    }

    /** {category_id: station_id|null} for every category. */
    public function assign(Request $request): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'stations' => 'array',
            'stations.*' => ['nullable', Rule::exists('kitchen_stations', 'id')->where('tenant_id', $tenant)],
        ]);
        foreach ($data['stations'] ?? [] as $categoryId => $stationId) {
            Category::query()->whereKey((int) $categoryId)->update(['kitchen_station_id' => $stationId ?: null]);
        }

        return back()->with('ok', __('Saved.'));
    }
}
