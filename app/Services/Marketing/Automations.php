<?php

namespace App\Services\Marketing;

use App\Billing\Credits;
use App\Models\CampaignAutomation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Entitlements;
use App\Services\Notify\GuestNotifier;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marketing automations (plan feature campaigns): messages the restaurant sends by itself — a thank-you after the
 * guest's first counted order (welcome), "we miss you" after N days without a visit (winback) and a birthday
 * greeting — each with an optional coupon. The scheduler runs them every 15 minutes, restaurant by restaurant, only
 * inside the automation's send window, only while the restaurant has WhatsApp credits (one per message, like
 * campaigns). Guests who asked to stop (whatsapp_opt_out) are skipped, and every send is a whatsapp_messages row
 * pointing at the automation, so a guest never gets the same automation twice within its cooldown.
 */
final class Automations
{
    /** Guests handled per automation and run; the next run continues with the rest. */
    public const BATCH = 200;

    /** A welcome goes out only to a first order placed within this many days (older guests are never "welcomed"). */
    public const WELCOME_WITHIN_DAYS = 2;

    public function __construct(private WhatsApp $whatsapp, private Credits $credits) {}

    /**
     * The restaurant's three automations, created with their default text the first time they are asked for.
     *
     * @return Collection<string, CampaignAutomation> keyed by kind
     */
    public function all(): Collection
    {
        $existing = CampaignAutomation::query()->with('coupon')->get()->keyBy('kind');
        $locale = WhatsApp::locale();
        foreach (array_keys(CampaignAutomation::KINDS) as $kind) {
            if (! $existing->has($kind)) {
                [$from, $to] = CampaignAutomation::DEFAULT_WINDOW[$kind];
                $existing[$kind] = CampaignAutomation::query()->create([
                    'kind' => $kind, 'is_enabled' => false, 'message' => CampaignAutomation::DEFAULT_MESSAGES[$kind][$locale],
                    'days' => 30, 'send_from' => $from, 'send_to' => $to,
                ]);
            }
        }

        return $existing->sortBy(fn ($a) => array_search($a->kind, array_keys(CampaignAutomation::KINDS), true));
    }

    /**
     * Guests this automation should reach right now (opted-out ones included; they are skipped when sending).
     *
     * @return Builder<Customer>
     */
    public function due(CampaignAutomation $automation): Builder
    {
        $q = Customer::query()->whereNotExists(function ($sub) use ($automation) {
            $sub->from('whatsapp_messages')->select(DB::raw(1))
                ->whereColumn('whatsapp_messages.customer_id', 'customers.id')
                ->where('whatsapp_messages.automation_id', $automation->id)
                ->where(fn ($w) => $w->whereNull('error')->orWhere('error', '!=', WhatsAppMessage::NO_CREDITS));
            if ($automation->cooldownDays() !== null) {
                $sub->where('whatsapp_messages.created_at', '>=', now()->subDays($automation->cooldownDays()));
            }
        });

        return match ($automation->kind) {
            'welcome' => $q->where('visits_count', 1)->where('last_visit_at', '>=', now()->subDays(self::WELCOME_WITHIN_DAYS)),
            'winback' => $q->where('visits_count', '>=', 1)->where('last_visit_at', '<', now()->subDays(max(1, $automation->days))),
            'birthday' => $q->whereNotNull('birthday')->whereRaw($this->monthDay('birthday').' = ?', [now()->format('m-d')]),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /**
     * Sends this automation to every guest due in the current restaurant. Returns how many messages went out;
     * stops at once when the credits run out (those guests are tried again on the next run).
     */
    public function run(CampaignAutomation $automation, ?\DateTimeInterface $at = null): int
    {
        $at ??= now();
        if (! $automation->is_enabled || ! $automation->inWindow($at) || ! Entitlements::current()->allows('campaigns')
            || $this->credits->balance(tenant()) < 1) {
            return 0;
        }
        $sent = 0;
        foreach ($this->due($automation)->orderBy('id')->limit(self::BATCH)->get() as $customer) {
            if ($customer->whatsapp_opt_out) {
                continue;
            }
            if ($this->credits->balance(tenant()) < 1) {
                break; // the rest wait for a top-up and the next run
            }
            $message = $this->whatsapp->record($customer->phone, 'automation', ['text' => $this->render($automation, $customer)], $customer->id, null, $automation->id);
            if (! $message) {
                continue; // not a valid mobile number
            }
            $message = $this->whatsapp->deliver($message);
            if ($message->error === WhatsAppMessage::NO_CREDITS) {
                break;
            }
            if ($message->status === 'sent') {
                $sent++;
            }
        }

        return $sent;
    }

    /** Scheduler: every enabled automation of every active restaurant, each in its own tenant context. */
    public function runAll(): int
    {
        $tenantIds = CampaignAutomation::query()->withoutGlobalScopes()->where('is_enabled', true)->distinct()->pluck('tenant_id');
        $sent = 0;
        foreach (Tenant::query()->whereIn('id', $tenantIds)->where('status', 'active')->get() as $tenant) {
            tenancy()->initialize($tenant);
            try {
                if (! Entitlements::for($tenant)->allows('campaigns') || $this->credits->balance($tenant) < 1) {
                    continue;
                }
                foreach (CampaignAutomation::query()->with('coupon')->where('is_enabled', true)->get() as $automation) {
                    $sent += $this->run($automation);
                }
            } finally {
                tenancy()->end();
            }
        }

        return $sent;
    }

    /** WhatsApp credits left for the current restaurant. */
    public function balance(): int
    {
        return $this->credits->balance(tenant());
    }

    /** The message one guest reads: {name}, {restaurant}, {coupon} and {link} (the menu, with the coupon filled in). */
    public function render(CampaignAutomation $automation, ?Customer $customer = null): string
    {
        $code = $automation->coupon?->code;
        $locale = WhatsApp::locale();
        $text = strtr($automation->message, [
            '{name}' => trim((string) $customer?->name) ?: ($locale === 'en' ? 'there' : 'عميلنا'),
            '{restaurant}' => GuestNotifier::senderName(),
            '{link}' => tenant()->url($code ? '?coupon='.rawurlencode($code) : ''),
            '{coupon}' => (string) $code,
        ]);

        return trim((string) preg_replace('/ {2,}/', ' ', $text));
    }

    /**
     * The last 30 days: messages sent, orders that used the automation's coupon and what they brought in.
     *
     * @return array{sent: int, redeemed: int, revenue: float}
     */
    public function results(CampaignAutomation $automation): array
    {
        $since = now()->subDays(30);
        $orders = $automation->coupon_id
            ? Order::query()->where('coupon_id', $automation->coupon_id)->where('status', '!=', 'cancelled')->where('created_at', '>=', $since)
            : null;

        return [
            'sent' => WhatsAppMessage::query()->where('automation_id', $automation->id)->where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'redeemed' => $orders ? $orders->clone()->count() : 0,
            'revenue' => $orders ? round((float) $orders->clone()->sum('total'), 2) : 0.0,
        ];
    }

    /** SQL for "MM-DD" of a date column on the current database. */
    private function monthDay(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%m-%d', $column)",
            'pgsql' => "to_char($column, 'MM-DD')",
            default => "DATE_FORMAT($column, '%m-%d')",
        };
    }
}
