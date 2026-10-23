<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Billing\LaunchOffer;
use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\AuditLog;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Seo\SocialProof;
use App\Services\Settings;
use App\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:30',
            'owner_name' => 'required|string|max:100',
            'email' => 'required|email|max:120|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|max:200',
            'plan' => ['required', Rule::exists('plans', 'code')],
            'trial' => 'nullable|boolean',
        ]);
        if ($error = Tenant::slugError($data['slug'])) {
            return back()->withInput()->withErrors(['slug' => $error]);
        }
        $phone = $data['phone'] ? normalize_phone($data['phone']) : null;
        if ($data['phone'] && (! $phone || User::query()->where('phone', $phone)->exists())) {
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
        // the restaurant's own settings and audit trail are read inside its tenant context
        tenancy()->initialize($tenant);
        try {
            $contact = ['phone' => Settings::get('phone'), 'whatsapp' => Settings::get('whatsapp')];
            $logo = trim((string) Settings::get('logo'));
            $platformActions = AuditLog::query()->where('action', 'like', '%_by_platform')->latest('id')->limit(8)->get();
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'plans' => Plan::query()->orderBy('sort_order')->get(),
            'users' => User::query()->where('tenant_id', $tenant->id)->with('roles')->get(),
            'addons' => AddonPrice::query()->where('type', '!=', 'credits')->orderBy('sort_order')->get(),
            'credits' => app(Credits::class)->balance($tenant),
            'creditLog' => CreditTransaction::query()->where('tenant_id', $tenant->id)->latest('id')->limit(8)->get(),
            'myAddons' => $tenant->subscription?->addons()->pluck('quantity', 'key')->all() ?? [],
            'invoices' => $tenant->invoices()->latest('id')->limit(10)->get(),
            'usage' => $usage,
            'contact' => $contact,
            'logo' => $logo !== '' ? '/storage/'.ltrim($logo, '/') : null,
            'platformActions' => $platformActions,
        ]);
    }

    /** Basic info of the restaurant: its name and the contact numbers shown to guests. Written to its audit trail. */
    public function info(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
        ]);
        $tenant->update(['name' => trim($data['name'])]);
        tenancy()->initialize($tenant);
        try {
            Settings::put(['phone' => trim((string) ($data['phone'] ?? '')), 'whatsapp' => trim((string) ($data['whatsapp'] ?? ''))]);
            Audit::record('restaurant.info_by_platform', $tenant->name, ['admin' => $request->user('admin')->email]);
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return back()->with('ok', __('Restaurant info saved.'));
    }

    /**
     * The restaurant's logo in the moving strip on the egmenu.app home page (App\Services\Seo\SiteShowcase), turned
     * on only after the owner agreed, with an optional place in the strip. Written to its audit trail.
     */
    public function showcase(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'showcase_on_site' => 'nullable|boolean',
            'showcase_sort' => 'nullable|integer|min:0|max:9999',
        ]);
        $tenant->update([
            'showcase_on_site' => $request->boolean('showcase_on_site'),
            'showcase_sort' => $data['showcase_sort'] ?? null,
        ]);
        tenancy()->initialize($tenant);
        try {
            Audit::record('restaurant.showcase_by_platform', $tenant->name, [
                'admin' => $request->user('admin')->email, 'on' => $tenant->showcase_on_site, 'sort' => $tenant->showcase_sort,
            ]);
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return back()->with('ok', $tenant->showcase_on_site ? __('The logo shows on egmenu.app.') : __('The logo is off egmenu.app.'));
    }

    /** EgWeb's demo / test places: kept out of the directory, the sitemap and the home page's list of places. */
    public function directory(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate(['hidden_from_directory' => 'nullable|boolean']);
        $tenant->update(['hidden_from_directory' => $request->boolean('hidden_from_directory')]);
        Cache::forget('seo.sitemap.'.platform_url(null, ''));
        SocialProof::flush();
        tenancy()->initialize($tenant);
        try {
            Audit::record('restaurant.directory_by_platform', $tenant->name, [
                'admin' => $request->user('admin')->email, 'hidden' => $tenant->hidden_from_directory,
            ]);
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return back()->with('ok', $tenant->hidden_from_directory
            ? __('Hidden from the directory and the sitemap.')
            : __('Back in the directory and the sitemap (if the owner keeps it listed).'));
    }

    /** Manual plan/status/period change (e.g. after an InstaPay or Vodafone Cash transfer). */
    public function subscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::in(array_keys(Subscription::STATUSES))],
            'cycle_months' => ['required', Rule::in(Plan::CYCLES)],
            'current_period_end' => 'nullable|date',
            'trial_ends_at' => 'nullable|date',
        ]);

        $sub = Subscription::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $data['plan_id'], 'status' => $data['status'], 'cycle_months' => $data['cycle_months'],
            'current_period_end' => $data['current_period_end'] ?? null, 'trial_ends_at' => $data['trial_ends_at'] ?? null,
        ]);
        if ($sub->status === 'active') {
            // a Complete subscription set by hand while the launch offer runs gets it too
            LaunchOffer::grant($sub->fresh(), $sub->current_period_start ?? now());
        }
        Entitlements::flush();

        return back()->with('ok', __('Subscription updated.'));
    }

    /** Grant / remove add-ons by hand (e.g. paid outside the system, or a free month). quantity 0 = off. */
    public function addons(Request $request, Tenant $tenant, Billing $billing): RedirectResponse
    {
        $data = $request->validate(['addons' => 'array', 'addons.*' => 'nullable|integer|min:0|max:50']);
        foreach (AddonPrice::query()->where('type', '!=', 'credits')->get() as $addon) {
            $qty = (int) ($data['addons'][$addon->key] ?? 0);
            $billing->setAddon($tenant, $addon->key, $addon->isFeaturePack() ? min(1, $qty) : $qty);
        }

        return back()->with('ok', __('Add-ons saved.'));
    }

    /** Adds or removes WhatsApp credits by hand (a gift, a correction, a transfer outside the invoices). */
    public function credits(Request $request, Tenant $tenant, Credits $credits): RedirectResponse
    {
        $data = $request->validate(['delta' => 'required|integer|between:-100000,100000|not_in:0', 'note' => 'nullable|string|max:60']);
        $credits->add($tenant, (int) $data['delta'], 'adjustment', trim('admin '.($data['note'] ?? '')));

        return back()->with('ok', __('WhatsApp credits updated.'));
    }

    /** Confirms a staff member's mobile by hand (the owner called, or WhatsApp is not set up yet). */
    public function verifyPhone(Tenant $tenant, int $user): RedirectResponse
    {
        $staff = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($user);
        if (! $staff->phone) {
            return back()->withErrors(['phone' => __('This user has no mobile number.')]);
        }
        $staff->markPhoneVerified();

        return back()->with('ok', __('Mobile number marked as confirmed.'));
    }

    /** Support case: the staff member lost their phone and recovery codes. Written to the restaurant's audit trail. */
    public function resetTwoFactor(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $staff = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($user);
        if ($staff->two_factor_secret === null) {
            return back()->withErrors(['two_factor' => __('This user has no two-factor authentication.')]);
        }
        $staff->disableTwoFactor();
        tenancy()->initialize($tenant);
        try {
            Audit::record('two_factor.reset_by_platform', $staff->name, ['id' => $staff->id, 'admin' => $request->user('admin')->email]);
        } finally {
            tenancy()->end();
        }

        return back()->with('ok', __('Two-factor authentication reset for :name. They can sign in with their password and set it up again.', ['name' => $staff->name]));
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
