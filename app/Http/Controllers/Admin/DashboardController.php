<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AdminSubscriptionReport;
use App\Services\AdminWorkspace;
use App\Services\Crm\LeadSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The admin home: on top what this member of the team works on today (App\Services\AdminWorkspace, by their
 * permissions), then the platform overview: restaurants, money, sign-ups of the last 30 days. All real data.
 */
class DashboardController extends Controller
{
    private const DAYS = 30;

    public function index(Request $request, AdminWorkspace $workspace): View
    {
        $admin = $request->user('admin');
        $subs = Subscription::query()->with('plan')->get();
        $since = local_day_start(local_at()->subDays(self::DAYS - 1));
        $openInvoices = Invoice::query()->where('status', 'open');

        return view('admin.home', [
            'counts' => [
                'tenants' => Tenant::query()->count(),
                'trialing' => $subs->where('status', 'trialing')->count(),
                'active' => $subs->where('status', 'active')->count(),
                'past_due' => $subs->where('status', 'past_due')->count(),
                'suspended' => Tenant::query()->where('status', 'suspended')->count(),
                'new_this_month' => Tenant::query()->where('created_at', '>=', local_at()->startOfMonth()->utc())->count(),
                'services_new' => ServiceRequest::query()->where('status', 'new')->count(),
                'unpaid_count' => (clone $openInvoices)->count(),
                'trials_ending' => $workspace->trialsEnding(),
            ],
            // Monthly recurring revenue of paying restaurants, the same figure as the subscriptions report
            'mrr' => $admin->allows('billing.manage') ? app(AdminSubscriptionReport::class)->mrr() : 0.0,
            'unpaid' => (float) (clone $openInvoices)->sum('total'),
            'signups' => $this->perDay(Tenant::query()->where('created_at', '>=', $since)->pluck('created_at'), $since),
            'revenue' => $admin->allows('billing.manage') ? $this->perDay(
                Invoice::query()->where('status', 'paid')->where('paid_at', '>=', $since)->get(['paid_at', 'total']), $since, 'paid_at', 'total') : [],
            'latest' => Tenant::query()->with('subscription.plan')->latest()->limit(6)->get(),
            'invoices' => $admin->allows('billing.manage') ? Invoice::query()->with('tenant', 'plan', 'payments')->latest('id')->limit(6)->get() : collect(),
            'admin' => $admin,
            // «متابعة العملاء»: new sign-ups nobody called, follow-ups due today and overdue
            'crm' => $admin->allows('crm.manage') ? app(LeadSearch::class)->counts() : null,
            'betaApps' => $this->betaApps(),
            'attention' => $workspace->attention($admin),
            'seat' => $workspace->seat($admin),
            'support' => $workspace->support($admin),
            'content' => $workspace->content($admin),
        ]);
    }

    /**
     * The Android app builds under test, uploaded to a hidden folder of public/ (APPS_BETA_DIR); none when unset.
     *
     * @return list<array{name: string, url: string, size: int}>
     */
    private function betaApps(): array
    {
        $dir = trim((string) config('services.apps_beta.dir'), '/');
        if ($dir === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $dir) || ! is_dir(public_path($dir))) {
            return [];
        }

        return collect(glob(public_path($dir).'/*.apk') ?: [])->sort()->map(fn (string $path) => [
            'name' => basename($path),
            'url' => asset($dir.'/'.basename($path)),
            'size' => (int) filesize($path),
        ])->values()->all();
    }

    /**
     * One bucket per day of the last 30 days (restaurant time) from $since (a UTC instant): a count of $rows, or the sum of $sumField.
     *
     * @param  Collection<int, mixed>  $rows
     * @return list<array{date: Carbon, value: float}>
     */
    private function perDay(Collection $rows, Carbon $since, ?string $dateField = null, ?string $sumField = null): array
    {
        $buckets = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $buckets[local_at($since)->addDays($i)->format('Y-m-d')] = 0.0;
        }
        foreach ($rows as $row) {
            $at = $dateField ? $row->{$dateField} : $row;
            $key = local_today($at);
            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += $sumField ? (float) $row->{$sumField} : 1;
            }
        }

        return collect($buckets)->map(fn (float $value, string $day) => ['date' => Carbon::parse($day), 'value' => $value])->values()->all();
    }
}
