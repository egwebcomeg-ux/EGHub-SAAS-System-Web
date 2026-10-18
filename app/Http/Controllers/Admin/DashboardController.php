<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Overview of the platform: restaurants, money, sign-ups of the last 30 days and what waits for the team. All real data. */
class DashboardController extends Controller
{
    private const DAYS = 30;

    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        $subs = Subscription::query()->with('plan')->get();
        $since = now(config('app.timezone'))->subDays(self::DAYS - 1)->startOfDay();
        $openInvoices = Invoice::query()->where('status', 'open');

        return view('admin.home', [
            'counts' => [
                'tenants' => Tenant::query()->count(),
                'trialing' => $subs->where('status', 'trialing')->count(),
                'active' => $subs->where('status', 'active')->count(),
                'past_due' => $subs->where('status', 'past_due')->count(),
                'suspended' => Tenant::query()->where('status', 'suspended')->count(),
                'new_this_month' => Tenant::query()->where('created_at', '>=', now(config('app.timezone'))->startOfMonth())->count(),
                'services_new' => ServiceRequest::query()->where('status', 'new')->count(),
                'unpaid_count' => (clone $openInvoices)->count(),
            ],
            // Monthly recurring revenue of paying restaurants (yearly cycles spread over 12 months).
            'mrr' => $subs->where('status', 'active')->sum(fn ($s) => $s->plan->priceFor($s->cycle_months) / max(1, $s->cycle_months)),
            'unpaid' => (float) (clone $openInvoices)->sum('total'),
            'signups' => $this->perDay(Tenant::query()->where('created_at', '>=', $since)->pluck('created_at'), $since),
            'revenue' => $admin->allows('billing.manage') ? $this->perDay(
                Invoice::query()->where('status', 'paid')->where('paid_at', '>=', $since)->get(['paid_at', 'total']), $since, 'paid_at', 'total') : [],
            'latest' => Tenant::query()->with('subscription.plan')->latest()->limit(6)->get(),
            'invoices' => $admin->allows('billing.manage') ? Invoice::query()->with('tenant', 'plan', 'payments')->latest('id')->limit(6)->get() : collect(),
            'admin' => $admin,
        ]);
    }

    /**
     * One bucket per day of the last 30 days (Cairo time): a count of $rows, or the sum of $sumField.
     *
     * @param  Collection<int, mixed>  $rows
     * @return list<array{date: Carbon, value: float}>
     */
    private function perDay(Collection $rows, Carbon $since, ?string $dateField = null, ?string $sumField = null): array
    {
        $buckets = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $buckets[$since->copy()->addDays($i)->format('Y-m-d')] = 0.0;
        }
        foreach ($rows as $row) {
            $at = $dateField ? $row->{$dateField} : $row;
            $key = Carbon::parse($at)->timezone(config('app.timezone'))->format('Y-m-d');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += $sumField ? (float) $row->{$sumField} : 1;
            }
        }

        return collect($buckets)->map(fn (float $value, string $day) => ['date' => Carbon::parse($day), 'value' => $value])->values()->all();
    }
}
