<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\MembershipTier;
use App\Models\PrepaidPackage;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Loyalty\Packages;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Advanced cards" (owner / manager): saves the cashback share, the membership tiers and the prepaid packages on sale
 * (their forms are sections of the rewards page, RewardsController) — plus the counter actions on a guest's packages
 * (sell one, take a use) for the cashier.
 */
class CardsController extends Controller
{
    /** The settings live on the rewards page: the old link opens the first of cashback, tiers and packages shown there. */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->to(route('dash.rewards').'#'.RewardsController::anchorFor($request->user(), 'cashback', 'tiers', 'packages'));
    }

    public function cashback(Request $request): RedirectResponse
    {
        abort_unless(Entitlements::current()->allows('cashback'), 403);
        $data = $request->validate(['percent' => 'required|numeric|min:0|max:50', 'min_order' => 'nullable|numeric|min:0|max:100000', 'expire_days' => 'nullable|integer|min:0|max:730']);
        Settings::put(['cashback_percent' => (string) round((float) $data['percent'], 2), 'cashback_min_order' => (string) round((float) ($data['min_order'] ?? 0), 2),
            'cashback_expire_days' => (string) (int) ($data['expire_days'] ?? 0)]);
        Audit::record('cards.cashback', (string) $data['percent']);

        return back()->with('ok', __('Cashback saved.'));
    }

    /** The whole list at once: rows left out are deleted, rows with an id updated, the rest created. */
    public function tiers(Request $request): RedirectResponse
    {
        abort_unless(Entitlements::current()->allows('membership'), 403);
        $data = $request->validate([
            'tiers' => 'array|max:6',
            'tiers.*.id' => ['nullable', 'integer', Rule::exists('membership_tiers', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'tiers.*.name' => 'required|string|max:40',
            'tiers.*.name_en' => 'nullable|string|max:40',
            'tiers.*.min_visits' => 'nullable|integer|min:0|max:100000',
            'tiers.*.min_spent' => 'nullable|numeric|min:0|max:10000000',
            'tiers.*.discount_percent' => 'nullable|numeric|min:0|max:50',
            'tiers.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $keep = [];
        foreach ($data['tiers'] ?? [] as $i => $row) {
            $attributes = ['name' => $row['name'], 'name_en' => $row['name_en'] ?? '', 'min_visits' => (int) ($row['min_visits'] ?? 0),
                'min_spent' => (float) ($row['min_spent'] ?? 0), 'discount_percent' => (float) ($row['discount_percent'] ?? 0), 'color' => $row['color'], 'sort_order' => $i];
            $tier = ! empty($row['id']) ? MembershipTier::query()->find($row['id']) : null;
            $tier ? $tier->update($attributes) : $tier = MembershipTier::query()->create($attributes);
            $keep[] = $tier->id;
        }
        MembershipTier::query()->whereNotIn('id', $keep)->delete();
        Audit::record('cards.tiers', count($keep).' tiers');

        return back()->with('ok', __('Membership tiers saved.'));
    }

    public function storePackage(Request $request): RedirectResponse
    {
        abort_unless(Entitlements::current()->allows('prepaid_packages'), 403);
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'name_en' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:200',
            'price' => 'required|numeric|min:1|max:'.WalletService::MAX_AMOUNT,
            'uses' => 'required|integer|min:1|max:500',
            'valid_days' => 'required|integer|min:1|max:730',
            'categories' => 'array',
            'categories.*' => [Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'items' => 'array',
            'items.*' => [Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
        ]);
        $package = PrepaidPackage::query()->create([
            'name' => $data['name'], 'name_en' => $data['name_en'] ?? '', 'description' => $data['description'] ?? '',
            'price' => $data['price'], 'uses' => $data['uses'], 'valid_days' => $data['valid_days'],
            'qualifying' => ['categories' => array_map('intval', $data['categories'] ?? []), 'items' => array_map('intval', $data['items'] ?? [])],
            'is_active' => true, 'sort_order' => (int) PrepaidPackage::query()->max('sort_order') + 1,
        ]);
        Audit::record('cards.package_created', $package->name, ['price' => $package->price, 'uses' => $package->uses]);

        return back()->with('ok', __('Package added.'));
    }

    /** Pause / resume a package, or remove it (sold copies keep their snapshot). */
    public function updatePackage(Request $request, PrepaidPackage $package): RedirectResponse
    {
        abort_unless(Entitlements::current()->allows('prepaid_packages'), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['toggle', 'delete'])]]);
        if ($data['action'] === 'delete') {
            Audit::record('cards.package_deleted', $package->name);
            $package->delete();

            return back()->with('ok', __('Package removed. Guests who bought it keep what is left.'));
        }
        $package->update(['is_active' => ! $package->is_active]);

        return back()->with('ok', $package->is_active ? __('Package back on sale.') : __('Package paused.'));
    }

    // ---- counter ----

    public function sellPackage(Request $request, Customer $customer, Packages $packages, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('prepaid_packages', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'method' => ['required', Rule::in(WalletService::TOPUP_METHODS)],
        ]);
        try {
            $copy = $packages->sell(PrepaidPackage::query()->findOrFail($data['package_id']), $customer, $data['method'], $request->user(), $shifts->current($request->user()));
        } catch (OrderException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }
        Audit::record('cards.package_sold', $customer->phone, ['package' => $copy->name, 'amount' => $copy->price_paid, 'method' => $data['method']]);

        return redirect()->route('dash.loyalty.desk', ['q' => $customer->phone])->with('ok', __('Package sold: :name', ['name' => $copy->name]));
    }

    public function usePackage(Request $request, CustomerPackage $copy, Packages $packages): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:100']);
        try {
            $packages->use($copy, $request->user(), null, $request->user()->branch_id, $data['note'] ?? '');
        } catch (OrderException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('ok', __(':left of :total left on :name', ['left' => $copy->uses_left, 'total' => $copy->uses_total, 'name' => $copy->name]));
    }

    /** Cancelling destroys prepaid value, so it is limited like a manual balance correction. */
    public function voidPackage(Request $request, CustomerPackage $copy, Packages $packages): RedirectResponse
    {
        abort_unless($request->user()->can('pos.discount'), 403);
        try {
            $packages->void($copy);
        } catch (OrderException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }
        Audit::record('cards.package_void', $copy->customer?->phone ?? '', ['package' => $copy->name, 'amount' => $copy->price_paid]);

        return back()->with('ok', __('Package voided.'));
    }
}
