<?php

namespace App\Services\Marketing;

use App\Billing\Credits;
use App\Jobs\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Customers\Segments;
use App\Services\Entitlements;
use App\Services\Notify\GuestNotifier;
use App\Services\WhatsApp\OwnNumber;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a campaign goes to (a guest group of App\Services\Customers\Segments, or a filter of Campaign::FILTERS), for
 * every channel; and the EgHub WhatsApp campaigns (plan feature campaigns): what one will cost, and the sending itself —
 * a queued job handles 50 guests at a time and queues the next batch. Guests who opted out are skipped (no
 * message, no credit). When the credits run out the campaign stops where it was and can be resumed after a top-up.
 * Runs inside the restaurant's tenant context.
 */
final class Campaigns
{
    public const BATCH = 50;

    public function __construct(private WhatsApp $whatsapp, private Credits $credits) {}

    /**
     * Guests of a group or filter, opted-out ones included (they are counted, then skipped).
     *
     * @return Builder<Customer>
     */
    public function audience(string $segment, ?float $value = null): Builder
    {
        $q = Customer::query();
        $n = max(0, (float) $value);

        return match ($segment) {
            'all' => $q,
            'inactive' => $q->where(function ($w) use ($n) {
                $cutoff = now()->subDays((int) max(1, $n));
                $w->where('last_visit_at', '<', $cutoff)->orWhere(fn ($w) => $w->whereNull('last_visit_at')->where('created_at', '<', $cutoff));
            }),
            'visits' => $q->where('visits_count', '>=', (int) max(1, $n)),
            'spent' => $q->where('total_spent', '>=', $n),
            'loyalty' => $q->whereHas('loyaltyCards'),
            default => Segments::is($segment) ? app(Segments::class)->apply($q, $segment) : $q->whereRaw('1 = 0'), // an unknown group reaches nobody
        };
    }

    /**
     * Guests, opted-out ones, and the credits a send takes (none from the staff's own phone or by SMS).
     *
     * @return array{audience: int, opted_out: int, recipients: int, cost: int, balance: int}
     */
    public function preview(string $segment, ?float $value = null, string $via = 'whatsapp'): array
    {
        $audience = $this->audience($segment, $value)->count();
        $optedOut = $this->audience($segment, $value)->where('whatsapp_opt_out', true)->count();
        $recipients = $audience - $optedOut;

        // from the restaurant's own number Meta bills its own account: no EgHub credit
        return ['audience' => $audience, 'opted_out' => $optedOut, 'recipients' => $recipients, 'cost' => $via !== 'whatsapp' || OwnNumber::covers('campaign') ? 0 : $recipients,
            'balance' => $this->credits->balance(tenant())];
    }

    /** Starts (or resumes) sending in the background. */
    public function start(Campaign $campaign): void
    {
        if (! Entitlements::current()->allows('campaigns')) {
            $campaign->update(['status' => 'stopped', 'stop_reason' => 'plan']);

            return;
        }
        $campaign->update(['status' => 'sending', 'stop_reason' => null, 'started_at' => $campaign->started_at ?? now()]);
        SendCampaignBatch::dispatch($campaign->id);
    }

    /** Sends the next batch. Returns true when more guests are left (the job then queues itself again). */
    public function sendBatch(Campaign $campaign): bool
    {
        $customers = $this->audience($campaign->segment, $campaign->segment_value)
            ->where('id', '>', $campaign->cursor)->orderBy('id')->limit(self::BATCH)->get();
        $stopped = false;
        foreach ($customers as $customer) {
            if ($customer->whatsapp_opt_out) {
                $campaign->skipped_count++;
                $campaign->cursor = $customer->id;

                continue;
            }
            $message = $this->whatsapp->record($customer->phone, 'campaign', ['text' => $this->render($campaign, $customer)], $customer->id, $campaign->id);
            if ($message) {
                $message = $this->whatsapp->deliver($message);
                if ($message->error === WhatsAppMessage::NO_CREDITS) {
                    $stopped = true; // this guest is tried again when the campaign is resumed

                    break;
                }
                $message->status === 'sent' ? $campaign->sent_count++ : $campaign->failed_count++;
            } else {
                $campaign->skipped_count++; // not a valid mobile number
            }
            $campaign->cursor = $customer->id;
        }

        if ($stopped) {
            $campaign->fill(['status' => 'stopped', 'stop_reason' => WhatsAppMessage::NO_CREDITS]);
        } elseif ($customers->count() < self::BATCH) {
            $campaign->fill(['status' => 'sent', 'finished_at' => now()]);
        }
        $campaign->save();

        return ! $stopped && $customers->count() === self::BATCH;
    }

    /** The message one guest reads: {name}, {restaurant}, {link} (the menu, with the coupon) and {coupon} (or {code}). */
    public function render(Campaign $campaign, ?Customer $customer = null): string
    {
        $code = $campaign->coupon?->code;
        $locale = WhatsApp::locale();

        return trim(strtr($campaign->message, [
            '{name}' => trim((string) $customer?->name) ?: ($locale === 'en' ? 'there' : 'عميلنا'),
            '{restaurant}' => GuestNotifier::senderName(),
            '{link}' => tenant()->url($code ? '?coupon='.rawurlencode($code) : ''),
            '{coupon}' => (string) $code,
            '{code}' => (string) $code,
        ]));
    }

    /** Scheduler: starts every scheduled campaign whose time has come, each in its restaurant's context. */
    public function startDue(): int
    {
        $due = Campaign::query()->withoutGlobalScopes()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get();
        foreach ($due as $campaign) {
            $tenant = Tenant::query()->find($campaign->tenant_id);
            if (! $tenant) {
                continue;
            }
            tenancy()->initialize($tenant);
            try {
                $this->start(Campaign::query()->findOrFail($campaign->id));
            } finally {
                tenancy()->end();
            }
        }

        return $due->count();
    }
}
