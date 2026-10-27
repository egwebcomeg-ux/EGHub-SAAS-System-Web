<?php

namespace App\Services\Customers;

use App\Jobs\SendCampaignSms;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Notify\GuestNotifier;
use App\Services\Sms\Sms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The free, manual WhatsApp campaign (channel "manual", no Meta account, no credits): the restaurant picks a group of
 * guests (Audiences), writes a message with {name}, {link} and {code}, optionally a personal one-time coupon per guest
 * and a personal account-claim link («ادعي عملاءك»); the staff then go down the send list and tap «ابعت» on each row,
 * which opens WhatsApp on their own phone with that guest's message ready. Orders that use a guest's coupon or come
 * from their link (?ref=, orders.campaign_recipient_id) count for the campaign. A guest who answered «وقف» is marked
 * opted out and is never in a list again. With an SMS provider configured the same list can go out as SMS.
 */
final class Outreach
{
    public const CHANNEL = 'manual';

    public const MAX_RECIPIENTS = 5000;

    public const PER_PAGE = 50;

    /**
     * @param  array{coupon?:array{type:string, value:float, days:int, min_order?:float}|null, claim?:bool, claim_days?:int}  $options
     */
    public function create(string $name, string $audience, string $message, array $options, User $by): Campaign
    {
        $guests = app(Audiences::class)->query($audience)->where('whatsapp_opt_out', false)->whereNotNull('phone')->where('phone', '!=', '')
            ->orderBy('id')->limit(self::MAX_RECIPIENTS)->get(['id', 'name', 'phone']);

        return DB::transaction(function () use ($name, $audience, $message, $options, $by, $guests) {
            $campaign = Campaign::query()->create([
                'name' => $name, 'channel' => self::CHANNEL, 'segment' => $audience, 'message' => $message, 'status' => 'sending',
                'scheduled_at' => now(), 'started_at' => now(), 'created_by' => $by->id, 'options' => $options,
            ]);
            $template = $options['coupon'] ?? null;
            $claims = app(ClaimLinks::class);
            $prefix = Coupon::defaultPrefix();
            foreach ($guests as $guest) {
                $coupon = $template ? Coupon::query()->create([
                    'code' => Coupon::generateCode($prefix), 'type' => $template['type'], 'value' => $template['value'],
                    'min_order' => $template['min_order'] ?? 0, 'max_uses' => 1, 'once_per_customer' => true, 'customer_id' => $guest->id,
                    'source' => 'campaign', 'note' => mb_substr($name, 0, 200), 'is_active' => true,
                    'expires_at' => Carbon::now()->addDays(max(1, (int) $template['days']))->endOfDay(),
                ]) : null;
                $ref = CampaignRecipient::newRef();
                CampaignRecipient::query()->create([
                    'campaign_id' => $campaign->id, 'customer_id' => $guest->id, 'phone' => $guest->phone, 'name' => mb_substr((string) $guest->name, 0, 100),
                    'coupon_id' => $coupon?->id, 'ref' => $ref,
                    'claim_link' => ! empty($options['claim']) ? $claims->issue($guest, (int) ($options['claim_days'] ?? ClaimLinks::DAYS)).'?ref='.$ref : null,
                ]);
            }
            $campaign->forceFill(['cursor' => $guests->count()])->save();

            return $campaign;
        });
    }

    /** The message one guest reads. */
    public function render(Campaign $campaign, CampaignRecipient $row): string
    {
        $code = $row->coupon?->code ?? '';
        $first = trim(explode(' ', trim($row->name))[0] ?? '');

        return trim(strtr($campaign->message, [
            '{name}' => $first !== '' ? $first : (app()->getLocale() === 'en' ? 'there' : 'يا فندم'),
            '{restaurant}' => GuestNotifier::senderName(),
            '{link}' => $this->link($row),
            '{code}' => $code,
            '{coupon}' => $code,
        ]));
    }

    /** The guest's own link: the claim link when the campaign has them, else the menu with the ref (and the code). */
    public function link(CampaignRecipient $row): string
    {
        if ($row->claim_link) {
            return $row->claim_link;
        }
        $query = 'ref='.$row->ref.($row->coupon ? '&coupon='.rawurlencode($row->coupon->code) : '');

        return tenant()->url('?'.$query);
    }

    public function whatsappUrl(Campaign $campaign, CampaignRecipient $row): string
    {
        return 'https://wa.me/'.whatsapp_number($row->phone).'?text='.rawurlencode($this->render($campaign, $row));
    }

    /**
     * Sent, opted out, and the orders the campaign brought (by a guest's coupon or their link), cancelled ones excluded.
     *
     * @return array{recipients:int, sent:int, opted_out:int, sms:int, orders:int, revenue:float}
     */
    public function stats(Campaign $campaign): array
    {
        $rows = CampaignRecipient::query()->where('campaign_id', $campaign->id);
        $ids = $rows->clone()->pluck('id');
        $coupons = $rows->clone()->whereNotNull('coupon_id')->pluck('coupon_id');
        $orders = Order::query()->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('campaign_recipient_id', $ids)->orWhereIn('coupon_id', $coupons->isEmpty() ? [0] : $coupons));

        return [
            'recipients' => $ids->count(),
            'sent' => $rows->clone()->whereNotNull('sent_at')->count(),
            'opted_out' => $rows->clone()->whereNotNull('opted_out_at')->count(),
            'sms' => $rows->clone()->where('sms_status', 'sent')->count(),
            'orders' => $orders->clone()->count(),
            'revenue' => round((float) $orders->clone()->sum('total'), 2),
        ];
    }

    public function markSent(CampaignRecipient $row, User $by): void
    {
        if ($row->sent_at === null) {
            $row->forceFill(['sent_at' => now(), 'sent_by' => $by->id])->save();
        }
    }

    /** «وقف»: the guest gets no campaign message again (WhatsApp or SMS), from any campaign. */
    public function optOut(CampaignRecipient $row): void
    {
        $row->forceFill(['opted_out_at' => now()])->save();
        Customer::query()->whereKey($row->customer_id)->update(['whatsapp_opt_out' => true]);
    }

    /** The campaign row a ?ref= on an order belongs to, at the current restaurant. */
    public static function recipientFor(?string $ref): ?int
    {
        if (! is_string($ref) || preg_match('/^[a-f0-9]{10}$/', $ref) !== 1) {
            return null;
        }

        return CampaignRecipient::query()->where('ref', $ref)->value('id');
    }

    /** Queues the SMS of every row not sent yet (opted-out guests are skipped when their turn comes). */
    public function startSms(Campaign $campaign): bool
    {
        if (! Sms::enabled()) {
            return false;
        }
        $options = (array) $campaign->options;
        $campaign->forceFill(['options' => ['sms' => 'sending'] + $options])->save();
        SendCampaignSms::dispatch($campaign->id);

        return true;
    }

    /** One batch of SMS; true when more rows are waiting. */
    public function sendSmsBatch(Campaign $campaign, int $batch = 50): bool
    {
        $driver = Sms::driver();
        if (! $driver) {
            return false;
        }
        $rows = CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereNull('sms_status')->with(['customer', 'coupon'])
            ->orderBy('id')->limit($batch)->get();
        foreach ($rows as $row) {
            if ($row->opted_out_at !== null || ! $row->customer || $row->customer->whatsapp_opt_out) {
                $row->forceFill(['sms_status' => 'skipped'])->save();

                continue;
            }
            $result = $driver->send($row->phone, $this->render($campaign, $row));
            $row->forceFill(['sms_status' => $result['ok'] ? 'sent' : 'failed', 'sms_sent_at' => $result['ok'] ? now() : null,
                'sms_error' => $result['error'] ? mb_substr($result['error'], 0, 190) : null])->save();
        }
        $more = $rows->count() === $batch;
        if (! $more) {
            $campaign->forceFill(['options' => ['sms' => 'done'] + (array) $campaign->options])->save();
        }

        return $more;
    }
}
