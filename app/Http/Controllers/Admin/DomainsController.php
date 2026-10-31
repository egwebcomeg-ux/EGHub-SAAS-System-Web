<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PlatformAuditLog;
use App\Services\DomainDns;
use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * EgWeb's side of custom domains (tenants.manage): after the domain is registered (egweb.com.eg) or pointed to the
 * server, and added as an alias with SSL on the hosting account, the admin approves it. Approving makes the menu and
 * QR codes use it (Tenant::customDomain, while the plan or an add-on includes custom domains); rejecting sends the
 * owner the reason on their domain page. «طلبات الدومين» lists every restaurant's domain, waiting ones first.
 */
class DomainsController extends Controller
{
    /** The status filter of the requests page: key => statuses */
    public const FILTERS = [
        'waiting' => ['requested', 'pending'],
        'active' => ['active'],
        'rejected' => ['rejected'],
    ];

    public function index(Request $request, DomainDns $dns): View
    {
        $filter = (string) $request->query('status', '');
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : '';
        $domains = Domain::query()->with('tenant.subscription.plan')
            ->whereHas('tenant')
            ->when($filter !== '', fn (Builder $q) => $q->whereIn('status', self::FILTERS[$filter]))
            ->orderByRaw("case when status in ('requested', 'pending') then 0 when status = 'active' then 1 else 2 end")
            ->latest('updated_at')->latest('id')
            ->paginate(25)->withQueryString();

        return view('admin.domains', [
            'domains' => $domains,
            'filter' => $filter,
            'counts' => collect(self::FILTERS)->map(fn (array $statuses) => Domain::query()->whereHas('tenant')->whereIn('status', $statuses)->count())->all(),
            'allowed' => $domains->getCollection()->mapWithKeys(fn (Domain $d) => [$d->id => Entitlements::for($d->tenant)->allows('custom_domain')])->all(),
            'dns' => $domains->getCollection()->mapWithKeys(fn (Domain $d) => [$d->id => $dns->last($d)])->all(),
        ]);
    }

    /** Connected: the menu and its QR codes use the domain from now on (when the plan includes it). */
    public function approve(Request $request, Domain $domain): RedirectResponse
    {
        $domain->update(['status' => 'active', 'note' => null, 'verified_at' => $domain->verified_at ?? now()]);
        $this->log($request, 'domain.approved', $domain);

        return back()->with('ok', __('Domain approved. The menu now opens on :domain.', ['domain' => $domain->domain]));
    }

    /** Rejected with a short reason the owner reads on their domain page. */
    public function reject(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        $domain->update(['status' => 'rejected', 'note' => $data['reason'], 'verified_at' => null]);
        $this->log($request, 'domain.rejected', $domain, ['reason' => $data['reason']]);

        return back()->with('ok', __('Domain rejected. The owner sees the reason on their domain page.'));
    }

    /** Asks the DNS now whether the domain points to our server (remembered for an hour on the list). */
    public function check(Domain $domain, DomainDns $dns): RedirectResponse
    {
        $result = $dns->check($domain);

        return back()->with('ok', match ($result['points']) {
            true => __(':domain points to our server.', ['domain' => $domain->domain]),
            false => __(':domain does not point to our server yet (it points to :ip).', ['domain' => $domain->domain, 'ip' => $result['ip'] ?? '—']),
            null => __('Add the server IP in the payment settings to check the DNS.'),
        });
    }

    /** Any status and note, from the restaurant's page. */
    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Domain::STATUSES))],
            'note' => 'nullable|string|max:300',
        ]);
        $domain->update([
            'status' => $data['status'], 'note' => $data['note'] ?? null,
            'verified_at' => $data['status'] === 'active' ? ($domain->verified_at ?? now()) : null,
        ]);
        $this->log($request, 'domain.updated', $domain, ['status' => $data['status']]);

        return back()->with('ok', __('Domain updated.'));
    }

    /** @param  array<string, mixed>  $meta */
    private function log(Request $request, string $action, Domain $domain, array $meta = []): void
    {
        PlatformAuditLog::record($request->user('admin'), $action, $domain->domain, ['tenant' => $domain->tenant_id, ...$meta]);
    }
}
