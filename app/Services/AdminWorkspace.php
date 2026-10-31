<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AssistantMessage;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\Post;
use App\Models\Prospect;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Crm\LeadSearch;
use App\Services\Crm\TeamStats;
use App\Support\AdminMenu;
use App\Support\SalesPlan;
use Illuminate\Support\Collection;

/**
 * The top of the admin home: what each member of the team works on today, by their permissions (a field rep's
 * visits and month, the telesales' follow-ups, the transfers finance confirms, the requests support answers, the
 * drafts of the content writer) and one "needs attention" list. Every number is counted from the database.
 */
final class AdminWorkspace
{
    /** Trials ending and renewals due within this many days are flagged. */
    public const SOON_DAYS = 7;

    public function __construct(private TeamStats $stats, private LeadSearch $leads) {}

    /**
     * What waits for someone, the items this admin may open only, the empty ones dropped: [key, label, count, url, tone].
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    public function attention(PlatformAdmin $admin): array
    {
        $soon = now()->addDays(self::SOON_DAYS);
        $items = [];
        if ($admin->allows('billing.manage')) {
            $items[] = ['transfers', __('Transfers to confirm'), Invoice::query()->where('status', 'open')
                ->whereHas('payments', fn ($p) => $p->where('status', 'pending')->whereNotIn('gateway', Payment::ONLINE_GATEWAYS))->count(),
                route('admin.invoices', ['status' => 'awaiting']), 'text-amber-700'];
            $items[] = ['overdue_invoices', __('Invoices past their due date'), Invoice::query()->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', now())->count(),
                route('admin.invoices', ['status' => 'open']), 'text-red-700'];
            $items[] = ['renewals', __('Renewals due this week'), Subscription::query()->where('status', 'active')->whereBetween('current_period_end', [now(), $soon])->count(),
                route('admin.tenants', ['status' => 'active']), 'text-navy-900'];
        }
        if ($admin->allows('tenants.view')) {
            $items[] = ['trials', __('Trials ending this week'), $this->trialsEnding(),
                route('admin.tenants', ['status' => 'trialing']), 'text-brand-700'];
            $items[] = ['past_due', __('Past due subscriptions'), Subscription::query()->where('status', 'past_due')->count(),
                route('admin.tenants', ['status' => 'past_due']), 'text-amber-700'];
        }
        if ($admin->allows('services.manage')) {
            $items[] = ['services', __('New service requests'), ServiceRequest::query()->where('status', 'new')->count(),
                route('admin.services'), 'text-brand-700'];
        }
        if ($admin->allows('crm.manage')) {
            $counts = $this->leads->counts();
            $items[] = ['follow_ups', __('Follow-up calls due or overdue'), $counts['today'] + $counts['overdue'],
                route('admin.crm', ['due' => 'due']), 'text-red-700'];
        }
        if ($admin->allows('settings.manage')) {
            $items[] = ['failed_jobs', __('Failed background jobs'), AdminMenu::failedJobs(), route('admin.system'), 'text-red-700'];
        }

        return array_values(array_filter($items, fn (array $item) => $item[2] > 0));
    }

    /**
     * A member of the sales team (App\Support\SalesPlan::seat): this month against the targets, and today's work.
     *
     * @return array{seat: string, card: array<string, mixed>, visits: Collection<int, Appointment>, late: int, followUps: int, booked: Collection<int, Appointment>}|null
     */
    public function seat(PlatformAdmin $admin): ?array
    {
        $seat = SalesPlan::seat($admin);
        if ($seat === null) {
            return null;
        }
        $tomorrow = local_day_start(local_at()->addDay()->toDateString());

        return [
            'seat' => $seat,
            'card' => $this->stats->card($admin, local_at()->format('Y-m')),
            // the field rep: today's booked visits, and the ones left from earlier days
            'visits' => Appointment::query()->with('prospect')->where('field_id', $admin->id)->where('status', 'scheduled')
                ->where('scheduled_at', '>=', local_day_start())->where('scheduled_at', '<', $tomorrow)->orderBy('scheduled_at')->get(),
            'late' => Appointment::query()->where('field_id', $admin->id)->where('status', 'scheduled')->where('scheduled_at', '<', local_day_start())->count(),
            // telesales: their places to call back today or before, and the visits they booked that are still ahead
            'followUps' => Prospect::query()->open()->where('caller_id', $admin->id)->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $tomorrow)->count()
                + ($admin->allows('crm.manage') ? Lead::query()->open()->where('crm_leads.assigned_admin_id', $admin->id)
                    ->where('crm_leads.next_follow_up_at', '<', $tomorrow)->count() : 0),
            'booked' => Appointment::query()->with(['prospect', 'field'])->where('set_by', $admin->id)->where('status', 'scheduled')
                ->where('scheduled_at', '>=', local_day_start())->orderBy('scheduled_at')->limit(5)->get(),
        ];
    }

    /**
     * Support: the newest requests to answer and the restaurants of the last week (whether the owner's mobile is confirmed).
     *
     * @return array{requests: Collection<int, ServiceRequest>, restaurants: Collection<int, array{tenant: Tenant, verified: bool}>}|null
     */
    public function support(PlatformAdmin $admin): ?array
    {
        if ($this->seesOverview($admin) || (! $admin->allows('services.manage') && ! $admin->allows('tenants.manage'))) {
            return null;
        }
        $restaurants = collect();
        if ($admin->allows('tenants.manage')) {
            $tenants = Tenant::query()->where('created_at', '>=', now()->subDays(self::SOON_DAYS))->latest()->limit(5)->get();
            // the owner is the restaurant's first account
            $owners = User::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenants->pluck('id'))->orderBy('id')->get()->unique('tenant_id')->keyBy('tenant_id');
            $restaurants = $tenants->map(fn (Tenant $t) => ['tenant' => $t, 'verified' => (bool) $owners->get($t->id)?->phone_verified_at]);
        }

        return [
            'requests' => $admin->allows('services.manage') ? ServiceRequest::query()->where('status', 'new')->latest()->limit(5)->get() : collect(),
            'restaurants' => $restaurants,
        ];
    }

    /**
     * Content: drafts and scheduled articles, and the latest questions to the website assistant.
     *
     * @return array{drafts: int, scheduled: int, latestDrafts: Collection<int, Post>, questions: Collection<int, AssistantMessage>}|null
     */
    public function content(PlatformAdmin $admin): ?array
    {
        if ($this->seesOverview($admin) || (! $admin->allows('content.manage') && ! $admin->allows('assistant.view'))) {
            return null;
        }
        $content = $admin->allows('content.manage');

        return [
            'drafts' => $content ? Post::query()->where('is_published', false)->count() : 0,
            'scheduled' => $content ? Post::query()->where('is_published', true)->where('published_at', '>', now())->count() : 0,
            'latestDrafts' => $content ? Post::query()->where('is_published', false)->latest('updated_at')->limit(3)->get() : collect(),
            'questions' => $admin->allows('assistant.view') ? AssistantMessage::query()->latest('id')->limit(5)->get() : collect(),
        ];
    }

    /**
     * Whoever handles the money (the owner, a manager, finance) reads the whole platform overview below and the
     * "needs attention" counts; the support and content blocks are for the members whose day is only that.
     */
    private function seesOverview(PlatformAdmin $admin): bool
    {
        return $admin->allows('billing.manage');
    }

    public function trialsEnding(): int
    {
        return Subscription::query()->where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(self::SOON_DAYS)])->count();
    }
}
