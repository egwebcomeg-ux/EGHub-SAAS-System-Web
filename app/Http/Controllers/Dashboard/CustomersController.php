<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Audit;
use App\Services\Customers\Audiences;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\Segments;
use App\Services\Customers\SignIn\PhoneCodeSignIn;
use App\Services\Loyalty\Membership;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The restaurant's own guest list: everyone who left a phone with an order or joined the loyalty card. */
class CustomersController extends Controller
{
    public function index(Request $request, Segments $segments, Audiences $audiences): View
    {
        $q = trim((string) $request->query('q', ''));
        $segment = Segments::is((string) $request->query('segment')) ? (string) $request->query('segment') : '';
        $audience = Audiences::is((string) $request->query('audience')) ? (string) $request->query('audience') : '';
        $sort = in_array($request->query('sort'), ['visits_count', 'total_spent', 'last_visit_at'], true) ? $request->query('sort') : 'last_visit_at';
        $customers = $segments->withRecentOrders($this->search($q, $segment, $audience, $audiences))->orderByDesc($sort)->orderByDesc('id')->paginate(30)->withQueryString();

        return view('dashboard.customers.index', [
            'customers' => $customers, 'q' => $q, 'sort' => $sort, 'segment' => $segment, 'audience' => $audience,
            // the tabs: who is in each group, what they spent, and what to do about them
            'audiences' => $audiences->summary(),
            // the sign-in codes this place sent its guests in the last 30 days, per channel, with their estimated cost
            'codeUsage' => PhoneCodeSignIn::usage(),
            'segments' => $segments, 'segmentCounts' => $segments->counts(),
            'stats' => [
                'total' => Customer::query()->count(),
                'returning' => Customer::query()->where('visits_count', '>', 1)->count(),
                'new_month' => Customer::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function show(Customer $customer): View
    {
        $accountUrl = GuestAccount::publicUrl($customer);
        $brand = trim(tr(Settings::get('brand_name'), Settings::get('brand_name_en'))) ?: (string) tenant()->name;

        return view('dashboard.customers.show', [
            'customer' => $customer->load(['loyaltyCards.program', 'loyaltyCards.availableRewards']),
            'orders' => Order::query()->where('customer_id', $customer->id)->latest('id')->limit(20)->get(),
            // "رابط حساب العميل": what the guest opens on any phone (orders, order again, addresses, points)
            'accountUrl' => $accountUrl,
            'accountWhatsapp' => 'https://wa.me/'.whatsapp_number($customer->phone).'?text='.rawurlencode(
                __('Your account at :place (orders, order again, addresses, points): :url', ['place' => $brand, 'url' => $accountUrl])),
        ]);
    }

    /** A new account link: the old account and card links, and every phone that remembered them, stop working. */
    public function rotateAccountLink(Customer $customer): RedirectResponse
    {
        GuestAccount::rotate($customer);
        Audit::record('customer.account_link_rotated', (string) $customer->id);

        return back()->with('ok', __('New link made. The old account and card links no longer open.'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100', 'phone_2' => 'nullable|string|max:20', 'birthday' => 'nullable|date|before:today',
            'notes' => 'nullable|string|max:1000',
        ]);
        $customer->update($data + ['marketing_opt_in' => $request->boolean('marketing_opt_in'), 'whatsapp_opt_out' => $request->boolean('whatsapp_opt_out')]);

        return back()->with('ok', __('Saved.'));
    }

    /** CSV that opens correctly in Excel (UTF-8 BOM) — the restaurant owns its guest list. */
    public function export(Request $request): StreamedResponse
    {
        // The file is written after the request handling returns, when the tenant scope may no longer be active:
        // pin the restaurant explicitly instead of relying on the global scope.
        $segment = Segments::is((string) $request->query('segment')) ? (string) $request->query('segment') : '';
        // the segment rules (VIP threshold, recent orders) are resolved now, while the tenant scope is still active
        $ids = $this->search(trim((string) $request->query('q', '')), $segment)->pluck('id');
        $rows = Customer::query()->withoutGlobalScopes()->where('tenant_id', tenant()->getTenantKey())->whereIn('id', $ids)->orderBy('id');
        $segments = app(Segments::class);
        $segments->vipThreshold();
        $tier = Membership::enabled() ? app(Membership::class)->tiers()->first() : null;

        return response()->streamDownload(function () use ($rows, $segments, $tier) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Name'), __('Mobile'), __('Visits'), __('Total spent'), __('Last visit'), __('Birthday'), __('Accepts offers'), __('Segment')]);
            $rows->chunk(500, function ($chunk) use ($out, $segments, $tier) {
                foreach ($chunk as $c) {
                    $badge = $segments->ofExported($c, $tier);
                    fputcsv($out, csv_row([$c->name, $c->phone, $c->visits_count, $c->total_spent, $c->last_visit_at?->format('Y-m-d'), $c->birthday?->format('Y-m-d'), $c->marketing_opt_in ? 1 : 0,
                        $badge ? Segments::label($badge) : '']));
                }
            });
            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A group of guests in the format of Meta's customer list (Ads Manager → Audiences → Create audience → Customer
     * list): phone in E.164 (+20…), first and last name, email when there is one. Owner and manager only
     * (customers.import, the permission that moves the list in and out), written in the audit log. Guests who asked
     * for no messages («وقف») are left out.
     */
    public function audienceExport(Request $request, Audiences $audiences): StreamedResponse
    {
        $audience = Audiences::is((string) $request->query('audience')) ? (string) $request->query('audience') : abort(404);
        $ids = $audiences->query($audience)->where('whatsapp_opt_out', false)->whereNotNull('phone')->where('phone', '!=', '')->pluck('id');
        Audit::record('customers.audience_exported', Audiences::label($audience), ['audience' => $audience, 'rows' => $ids->count()]);
        $rows = Customer::query()->withoutGlobalScopes()->where('tenant_id', tenant()->getTenantKey())->whereIn('id', $ids)->orderBy('id');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['phone', 'fn', 'ln', 'email', 'country']);
            $rows->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $c) {
                    $parts = preg_split('/\s+/u', trim((string) $c->name), 2) ?: [];
                    fputcsv($out, csv_row(['+20'.ltrim((string) $c->phone, '0'), mb_strtolower($parts[0] ?? ''), mb_strtolower($parts[1] ?? ''),
                        mb_strtolower((string) ($c->email ?? '')), 'eg']));
                }
            });
            fclose($out);
        }, 'meta-audience-'.$audience.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function search(string $q, string $segment = '', string $audience = '', ?Audiences $audiences = null)
    {
        $query = $audience !== '' ? ($audiences ?? app(Audiences::class))->query($audience) : Customer::query();
        $query->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%$q%")
            ->orWhere('phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')));

        return $segment !== '' ? app(Segments::class)->apply($query, $segment) : $query;
    }
}
