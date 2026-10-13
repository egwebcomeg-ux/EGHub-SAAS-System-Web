<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Plans are data: prices, which features each plan unlocks and its limits are all edited here. */
class PlansController extends Controller
{
    public function index(): View
    {
        $plans = Plan::query()->with('features')->orderBy('sort_order')->get();
        return view('admin.plans.index', [
            'plans'  => $plans,
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()]),
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', ['plan' => $plan, 'rights' => $plan->load('features')->entitlements()]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:60',
            'name_en'         => 'nullable|string|max:60',
            'tagline'         => 'nullable|string|max:200',
            'tagline_en'      => 'nullable|string|max:200',
            'monthly_price'   => 'required|numeric|min:0|max:100000',
            'original_price'  => 'nullable|numeric|min:0|max:100000',
            'discount_6'      => 'nullable|numeric|min:0|max:90',
            'discount_12'     => 'nullable|numeric|min:0|max:90',
            'sort_order'      => 'nullable|integer',
            'features'        => 'array',
            'features.*'      => 'string',
            'limits'          => 'array',
            'limits.*'        => 'nullable|integer|min:0|max:1000000',
            'unlimited'       => 'array',
        ]);

        $plan->update([
            'name' => $data['name'], 'name_en' => $data['name_en'] ?? '', 'tagline' => $data['tagline'] ?? '',
            'tagline_en' => $data['tagline_en'] ?? '', 'monthly_price' => $data['monthly_price'],
            'original_price' => $data['original_price'] ?? null, 'sort_order' => $data['sort_order'] ?? $plan->sort_order,
            'cycle_discounts' => ['6' => (float) ($data['discount_6'] ?? 0), '12' => (float) ($data['discount_12'] ?? 0)],
            'is_public' => $request->boolean('is_public'), 'is_featured' => $request->boolean('is_featured'),
        ]);

        $on = $data['features'] ?? [];
        foreach (array_keys(Plan::FEATURES) as $key) {
            $plan->features()->updateOrCreate(['key' => $key], ['type' => 'feature', 'value' => in_array($key, $on, true) ? 1 : 0]);
        }
        foreach (array_keys(Plan::LIMITS) as $key) {
            $value = isset($data['unlimited'][$key]) ? null : (int) ($data['limits'][$key] ?? 0);
            $plan->features()->updateOrCreate(['key' => $key], ['type' => 'limit', 'value' => $value]);
        }
        Entitlements::flush();

        return redirect()->route('admin.plans')->with('ok', __('Plan saved.'));
    }
}
