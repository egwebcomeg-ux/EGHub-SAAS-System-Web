<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Restaurants and their subscriptions, managed by EgWeb. */
class TenantsController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $tenants = Tenant::query()->with('subscription.plan')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('id', 'like', "%$q%")->orWhere('name', 'like', "%$q%")))
            ->when($status === 'suspended', fn ($w) => $w->where('status', 'suspended'))
            ->when(in_array($status, array_keys(Subscription::STATUSES), true), fn ($w) => $w->whereHas('subscription', fn ($s) => $s->where('status', $status)))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.tenants.index', compact('tenants', 'q', 'status'));
    }

    public function create(): View
    {
        return view('admin.tenants.create', ['plans' => Plan::query()->orderBy('sort_order')->get()]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug'))), 'email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'slug'       => 'required|string|max:30',
            'owner_name' => 'required|string|max:100',
            'email'      => 'required|email|max:120|unique:users,email',
            'phone'      => 'nullable|string|max:20',
            'password'   => 'required|string|min:8|max:200',
            'plan'       => ['required', Rule::exists('plans', 'code')],
            'trial'      => 'nullable|boolean',
        ]);
        if ($error = Tenant::slugError($data['slug'])) {
            return back()->withInput()->withErrors(['slug' => $error]);
        }
        $phone = $data['phone'] ? normalize_phone($data['phone']) : null;
        if ($data['phone'] && (!$phone || User::query()->where('phone', $phone)->exists())) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid, unused Egyptian mobile number.')]);
        }

        $tenant = $provisioner->create([
            'slug' => $data['slug'], 'name' => $data['name'], 'owner_name' => $data['owner_name'], 'email' => $data['email'],
            'phone' => $phone, 'password' => $data['password'], 'plan' => $data['plan'], 'trial' => $request->boolean('trial'),
        ]);

        return redirect()->route('admin.tenants.show', $tenant)->with('ok', __('Restaurant created.'));
    }

    public function show(Tenant $tenant): View
    {
        $tenant->load('subscription.plan');
        $rights = Entitlements::for($tenant);
        $usage = [];
        foreach (array_keys(Plan::LIMITS) as $key) {
            $usage[$key] = ['used' => $rights->usage($key), 'limit' => $rights->limit($key)];
        }

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'plans'  => Plan::query()->orderBy('sort_order')->get(),
            'users'  => User::query()->where('tenant_id', $tenant->id)->with('roles')->get(),
            'addons' => AddonPrice::query()->orderBy('sort_order')->get(),
            'myAddons' => $tenant->subscription?->addons()->pluck('quantity', 'key')->all() ?? [],
            'invoices' => $tenant->invoices()->latest('id')->limit(10)->get(),
            'usage'  => $usage,
        ]);
    }

    /** Manual plan/status/period change (e.g. after an InstaPay or Vodafone Cash transfer). */
    public function subscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan_id'            => ['required', Rule::exists('plans', 'id')],
            'status'             => ['required', Rule::in(array_keys(Subscription::STATUSES))],
            'cycle_months'       => ['required', Rule::in(Plan::CYCLES)],
            'current_period_end' => 'nullable|date',
            'trial_ends_at'      => 'nullable|date',
        ]);

        Subscription::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $data['plan_id'], 'status' => $data['status'], 'cycle_months' => $data['cycle_months'],
            'current_period_end' => $data['current_period_end'] ?? null, 'trial_ends_at' => $data['trial_ends_at'] ?? null,
        ]);
        Entitlements::flush();

        return back()->with('ok', __('Subscription updated.'));
    }

    /** Grant / remove add-ons by hand (e.g. paid outside the system, or a free month). quantity 0 = off. */
    public function addons(Request $request, Tenant $tenant, \App\Billing\Billing $billing): RedirectResponse
    {
        $data = $request->validate(['addons' => 'array', 'addons.*' => 'nullable|integer|min:0|max:50']);
        foreach (AddonPrice::query()->get() as $addon) {
            $qty = (int) ($data['addons'][$addon->key] ?? 0);
            $billing->setAddon($tenant, $addon->key, $addon->isFeaturePack() ? min(1, $qty) : $qty);
        }
        return back()->with('ok', __('Add-ons saved.'));
    }

    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => 'suspended', 'suspended_at' => now()]);
        return back()->with('ok', __('Restaurant suspended. Its menu is offline; the dashboard stays reachable.'));
    }

    public function activate(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => 'active', 'suspended_at' => null]);
        return back()->with('ok', __('Restaurant activated.'));
    }
}
