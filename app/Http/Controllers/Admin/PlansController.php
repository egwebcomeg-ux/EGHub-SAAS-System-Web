<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Plans are data: prices, which features each plan unlocks and its limits are all edited here. */
class PlansController extends Controller
{
    /** Plans tab: one card per plan, the full comparison behind a toggle. */
    public function index(): View
    {
        $plans = Plan::query()->with('features')->orderBy('sort_order')->get();

        return view('admin.plans.index', [
            'plans' => $plans,
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()]),
            'restaurants' => Subscription::query()->selectRaw('plan_id, count(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id'),
            'addonCount' => AddonPrice::query()->count(),
        ]);
    }

    /** Add-ons tab: a compact list with search and type filter; each add-on is edited on its own page. */
    public function addonsIndex(): View
    {
        return view('admin.plans.addons', [
            'addons' => AddonPrice::query()->orderBy('type')->orderBy('sort_order')->get(),
            'plans' => Plan::query()->orderBy('sort_order')->get()->keyBy('code'),
            'buyers' => SubscriptionAddon::query()->where('quantity', '>', 0)->select('key')->selectRaw('count(*) as total')->groupBy('key')->pluck('total', 'key'),
        ]);
    }

    public function editAddon(AddonPrice $addon): View
    {
        return view('admin.plans.addon-edit', ['addon' => $addon, 'plans' => Plan::query()->orderBy('sort_order')->get()]);
    }

    /** Prices / names / on-off of the add-ons; a feature pack's feature list is edited here too. */
    public function addons(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'addons' => 'array',
            'addons.*.name' => 'required|string|max:60',
            'addons.*.name_en' => 'nullable|string|max:60',
            'addons.*.description' => 'nullable|string|max:200',
            'addons.*.monthly_price' => 'required|numeric|min:0|max:100000',
            'addons.*.features' => 'array',
            'addons.*.features.*' => ['string', Rule::in(array_keys(Plan::FEATURES))],
            'addons.*.min_plan' => ['nullable', Rule::exists('plans', 'code')],
        ]);
        foreach ($data['addons'] ?? [] as $id => $a) {
            $addon = AddonPrice::query()->find((int) $id);
            if (! $addon) {
                continue;
            }
            $addon->update([
                'name' => $a['name'], 'name_en' => $a['name_en'] ?? '', 'description' => $a['description'] ?? '',
                'monthly_price' => $a['monthly_price'], 'is_active' => $request->boolean("addons.$id.is_active"),
                'features' => $addon->isFeaturePack() ? array_values($a['features'] ?? []) : null,
                'per_branch' => $request->boolean("addons.$id.per_branch"), 'min_plan' => $a['min_plan'] ?? null,
            ]);
        }
        Entitlements::flush();

        // the one-add-on edit page goes back to the list; the old all-in-one form stays where it was
        return $request->input('return') === 'addons'
            ? redirect()->route('admin.addons')->with('ok', __('Add-ons saved.'))
            : back()->with('ok', __('Add-ons saved.'));
    }

    /** The "on sale" switch of the add-ons list. */
    public function toggleAddon(AddonPrice $addon): RedirectResponse
    {
        $addon->update(['is_active' => ! $addon->is_active]);
        Entitlements::flush();

        return back()->with('ok', $addon->is_active ? __(':name is on sale.', ['name' => $addon->displayName()]) : __(':name is no longer on sale.', ['name' => $addon->displayName()]));
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', ['plan' => $plan, 'rights' => $plan->load('features')->entitlements()]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'name_en' => 'nullable|string|max:60',
            'tagline' => 'nullable|string|max:200',
            'tagline_en' => 'nullable|string|max:200',
            'monthly_price' => 'required|numeric|min:0|max:100000',
            'original_price' => 'nullable|numeric|min:0|max:100000',
            'discount_6' => 'nullable|numeric|min:0|max:90',
            'discount_12' => 'nullable|numeric|min:0|max:90',
            'sort_order' => 'nullable|integer',
            'features' => 'array',
            'features.*' => 'string',
            'limits' => 'array',
            'limits.*' => 'nullable|integer|min:0|max:1000000',
            'unlimited' => 'array',
        ]);

        $plan->update([
            'name' => $data['name'], 'name_en' => $data['name_en'] ?? '', 'tagline' => $data['tagline'] ?? '',
            'tagline_en' => $data['tagline_en'] ?? '', 'monthly_price' => $data['monthly_price'],
            'original_price' => $data['original_price'] ?? null, 'sort_order' => $data['sort_order'] ?? $plan->sort_order,
            'cycle_discounts' => ['6' => (float) ($data['discount_6'] ?? 0), '12' => (float) ($data['discount_12'] ?? 0)],
            'is_public' => $request->boolean('is_public'), 'is_featured' => $request->boolean('is_featured'),
            'contact_sales' => $request->boolean('contact_sales'),
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
