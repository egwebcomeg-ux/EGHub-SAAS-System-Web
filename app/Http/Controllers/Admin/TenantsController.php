<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Billing\LaunchOffer;
use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\AuditLog;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Models\TenantDiscount;
use App\Models\User;
use App\Services\AdminTenantSearch;
use App\Services\Audit;
use App\Services\Crm\ProspectLinker;
use App\Services\DemoContent;
use App\Services\Entitlements;
use App\Services\Seo\SocialProof;
use App\Services\Settings;
use App\Services\TenantEraser;
use App\Services\TenantProvisioner;
use App\Services\WelcomeKit;
use App\Support\BusinessType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Restaurants and their subscriptions, managed by EgWeb. */
class TenantsController extends Controller
{
    /** Search (link, name, any account's email or mobile, custom domain) and filters (App\Services\AdminTenantSearch). */
    public function index(Request $request, AdminTenantSearch $search): View
    {
        $filters = AdminTenantSearch::filters($request);
        $tenants = $search->listing($filters)->paginate(25)->withQueryString();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'filters' => $filters,
            'filtered' => AdminTenantSearch::isFiltered($filters),
            'typeCounts' => $search->typeCounts($filters),
            'types' => AdminTenantSearch::typesOf($tenants->pluck('id')),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    /**
     * The list with the same search, filters and sort as the page, as a CSV that Excel opens in Arabic (UTF-8 BOM).
     * The business types are read once per chunk of 500 places, never per row.
     */
    public function export(Request $request, AdminTenantSearch $search): StreamedResponse
    {
        $rows = $search->listing(AdminTenantSearch::filters($request));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Restaurant'), __('Link'), __('Business type'), __('Plan'), __('Subscription'), __('Ends'), __('Menu'), __('Created'), __('Last order')]);
            $rows->chunk(500, function ($chunk) use ($out) {
                $types = AdminTenantSearch::typesOf($chunk->pluck('id'));
                foreach ($chunk as $tenant) {
                    $sub = $tenant->subscription;
                    fputcsv($out, csv_row([
                        $tenant->name, $tenant->id, BusinessType::label($types[$tenant->id]),
                        (string) $sub?->plan?->displayName(), (string) $sub?->statusLabel(), $sub?->endsAt() ? local_today($sub->endsAt()) : '',
                        $tenant->status === 'suspended' ? __('Suspended') : __('Online'), local_today($tenant->created_at),
                        $tenant->last_order_at ? local_today($tenant->last_order_at) : '',
                    ]));
                }
            });
            fclose($out);
        }, 'restaurants-'.local_today().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): View
    {
        return view('admin.tenants.create', ['plans' => Plan::query()->orderBy('sort_order')->get()]);
    }

    /**
     * A restaurant for a prospect: the owner gets a strong temporary password (changed at the first sign-in) and a
     * welcome kit (App\Services\WelcomeKit); with "fill demo data" the other modules get sample rows
     * (App\Services\DemoContent). No owner email: a placeholder {slug}@eghub.app that is never mailed.
     * trial_days 0 = no trial, the plan starts active (set by hand after a transfer).
     */
    public function store(Request $request, TenantProvisioner $provisioner, WelcomeKit $kit, DemoContent $demo): RedirectResponse
    {
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug'))), 'email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:30',
            'business_type' => ['nullable', Rule::in(array_keys(BusinessType::TYPES))],
            'owner_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:120|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'plan' => ['required', Rule::exists('plans', 'code')],
            'trial_days' => 'nullable|integer|min:0|max:90',
            'demo' => 'nullable|boolean',
        ]);
        if ($error = Tenant::slugError($data['slug'])) {
            return back()->withInput()->withErrors(['slug' => $error]);
        }
        $phone = $data['phone'] ? normalize_phone($data['phone']) : null;
        if ($data['phone'] && (! $phone || User::query()->where('phone', $phone)->exists())) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid, unused Egyptian mobile number.')]);
        }
        $email = $data['email'] ?: WelcomeKit::placeholderEmail($data['slug']);
        if (! $data['email'] && User::query()->where('email', $email)->exists()) {
            return back()->withInput()->withErrors(['email' => __('Enter the owner\'s email: the placeholder address of this link is taken.')]);
        }
        $trialDays = (int) ($data['trial_days'] ?? 0);

        $tenant = $provisioner->create([
            'slug' => $data['slug'], 'name' => $data['name'], 'owner_name' => $data['owner_name'], 'email' => $email,
            'phone' => $phone, 'password' => Str::password(40), 'plan' => $data['plan'], 'trial' => $trialDays > 0,
            'trial_days' => max(1, $trialDays), 'business_type' => $data['business_type'] ?? BusinessType::DEFAULT,
        ]);
        // a prospect the team brought in: a manual lead of the follow-up, followed by whoever created it
        Lead::for($tenant)->update(['source' => 'manual', 'assigned_admin_id' => $request->user('admin')->allows('crm.manage') ? $request->user('admin')->id : null]);
        $owner = $kit->owner($tenant) ?? User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $kit->issue($tenant, $owner);
        app(ProspectLinker::class)->link($tenant, $phone);
        // the sample rows are a nicety: the restaurant and its kit stand even when they fail (all or nothing inside)
        $seeded = null;
        if ($request->boolean('demo')) {
            try {
                $seeded = $demo->seed($tenant);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $mailed = $kit->email($tenant, $owner);
        PlatformAuditLog::record($request->user('admin'), 'tenant.created_for_prospect', $tenant->id, [
            'name' => $tenant->name, 'plan' => $data['plan'], 'trial_days' => $trialDays, 'demo' => $seeded !== null,
            'placeholder_email' => ! $data['email'], 'emailed' => $mailed,
        ]);

        return redirect()->route('admin.tenants.show', $tenant)->with('ok', $mailed
            ? __('Restaurant created. The welcome kit is ready and was emailed to the owner.')
            : __('Restaurant created. The welcome kit is ready: send it on WhatsApp or copy the link.'))
            ->with('kit_created', true);
    }

    /** A new temporary password and a new welcome page (older links stop working); emailed again to a real address. */
    public function welcomeKit(Request $request, Tenant $tenant, WelcomeKit $kit): RedirectResponse
    {
        $owner = $kit->owner($tenant) ?? User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->role('owner')->orderBy('id')->first();
        if (! $owner) {
            return back()->withErrors(['welcome_kit' => __('This restaurant has no owner account.')]);
        }
        $kit->issue($tenant, $owner);
        $mailed = $request->boolean('email') && $kit->email($tenant, $owner);
        PlatformAuditLog::record($request->user('admin'), 'tenant.welcome_kit_regenerated', $tenant->id, ['user' => $owner->id, 'emailed' => $mailed]);
        tenancy()->initialize($tenant);
        try {
            Audit::record('user.password_set_by_platform', $owner->name, ['id' => $owner->id, 'admin' => $request->user('admin')->email, 'welcome_kit' => true]);
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return back()->with('ok', $mailed
            ? __('A new welcome kit is ready and was emailed to the owner. Older links no longer work.')
            : __('A new welcome kit is ready. Older links no longer work.'))->with('kit_created', true);
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
            $demoRows = array_sum(array_map('count', DemoContent::ids()));
        } finally {
            tenancy()->end();
            Settings::flush();
        }
        $kit = app(WelcomeKit::class);
        $kitOwner = $kit->owner($tenant);
        $welcomeKit = $kitOwner ? [
            'url' => $kit->url($tenant),
            'expires_at' => $kit->expiresAt($tenant),
            'owner' => $kitOwner,
            'login' => $kitOwner->email ?: $kitOwner->phone,
            'placeholder' => WelcomeKit::isPlaceholder($kitOwner->email, $tenant),
            // the temporary password stays readable here only until the owner picks their own
            'password' => $kitOwner->must_change_password ? $kitOwner->temporary_password : null,
            'whatsapp' => $kit->whatsappUrl($tenant, $kitOwner),
        ] : null;

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
            'welcomeKit' => $welcomeKit,
            'demoRows' => $demoRows,
            // the sales follow-up and a discount given on a call (admin only)
            'lead' => $tenant->lead,
            'teamDiscount' => TenantDiscount::usableFor($tenant),
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
     * The restaurant's logo in the moving strip on the eghub.app home page (App\Services\Seo\SiteShowcase), turned
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

        return back()->with('ok', $tenant->showcase_on_site ? __('The logo shows on eghub.app.') : __('The logo is off eghub.app.'));
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

        $sub = SubscriptionChange::because('admin', fn () => Subscription::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $data['plan_id'], 'status' => $data['status'], 'cycle_months' => $data['cycle_months'],
            'current_period_end' => isset($data['current_period_end']) ? local_day_start($data['current_period_end']) : null,
            'trial_ends_at' => isset($data['trial_ends_at']) ? local_day_start($data['trial_ends_at']) : null,
        ]));
        if ($sub->status === 'active') {
            // a Complete subscription set by hand while the launch offer runs gets it too
            LaunchOffer::grant($sub->fresh(), $sub->current_period_start ?? now());
            app(Billing::class)->claimLaunchOfferBranch($tenant);
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

    /**
     * Deletes the restaurant for good (TenantEraser): the platform owner only, with the slug typed to confirm; one
     * that paid in the last 30 days needs "delete anyway". Recorded in the platform audit log, which outlives it.
     */
    public function destroy(Request $request, Tenant $tenant, TenantEraser $eraser): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->isOwner(), 403, __('Only the platform owner can delete a restaurant for good.'));
        $request->validate([
            'confirm_slug' => ['required', 'string', Rule::in([$tenant->id])],
            'force' => 'nullable|boolean',
        ], ['confirm_slug.in' => __('Type the restaurant link (:slug) exactly to confirm.', ['slug' => $tenant->id])]);

        $name = $tenant->name;
        $paidRecently = $eraser->paidRecently($tenant);
        try {
            $result = $eraser->erase($tenant, $request->boolean('force'));
        } catch (\DomainException $e) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        }
        PlatformAuditLog::record($admin, 'tenant.deleted', $tenant->id, [
            'name' => $name, 'forced' => $request->boolean('force'), 'paid_recently' => $paidRecently,
            'rows' => array_sum($result['rows']), 'folders' => $result['folders'],
        ]);

        return redirect()->route('admin.tenants')->with('ok', __(':name was deleted for good.', ['name' => $name]));
    }
}
