<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WhatsApp message to a segment of the guest list, sent now or at a time (App\Services\Marketing\Campaigns). */
class Campaign extends TenantModel
{
    /** key => [arabic, english, what segment_value means (null = none)] */
    public const SEGMENTS = [
        'all' => ['كل العملاء', 'All customers', null],
        'inactive' => ['مزاروش من :n يوم', 'Not visited for :n days', 'days'],
        'visits' => ['زاروا :n مرة أو أكتر', 'Visited :n times or more', 'visits'],
        'birthday' => ['عيد ميلادهم الأسبوع ده', 'Birthday this week', null],
        'loyalty' => ['معاهم كارت ولاء', 'Loyalty card holders', null],
        'spent' => ['صرفوا :n جنيه أو أكتر', 'Spent :n EGP or more', 'amount'],
        // computed segments (App\Services\Customers\Segments)
        'new' => ['العملاء الجداد (أول زيارة في آخر 30 يوم)', 'New guests (first visit in the last 30 days)', null],
        'regular' => ['العملاء الدايمين (3 طلبات أو أكتر في 90 يوم)', 'Regulars (3+ orders in 90 days)', null],
        'vip' => ['عملاء VIP (أعلى 10% صرف أو أعلى عضوية)', 'VIP guests (top 10% spend or top tier)', null],
        'at_risk' => ['بيبعدوا (دايمين ومزاروش من 30 لـ 60 يوم)', 'At risk (regulars away 30 to 60 days)', null],
        'lost' => ['راحوا (مزاروش من أكتر من 60 يوم)', 'Lost (no visit for more than 60 days)', null],
    ];

    public const STATUSES = [
        'scheduled' => ['متجدولة', 'Scheduled'],
        'sending' => ['بتتبعت', 'Sending'],
        'sent' => ['اتبعتت', 'Sent'],
        'stopped' => ['وقفت', 'Stopped'],
        'cancelled' => ['اتلغت', 'Cancelled'],
    ];

    protected function casts(): array
    {
        return [
            'segment_value' => 'float', 'options' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'cursor' => 'integer', 'sent_count' => 'integer', 'failed_count' => 'integer', 'skipped_count' => 'integer',
        ];
    }

    /** The segment's label with its number filled in (":n" → "N" when there is none yet). */
    public static function segmentLabel(string $segment, ?string $n = null): string
    {
        $l = self::SEGMENTS[$segment] ?? [$segment, $segment];

        return str_replace(':n', $n ?? 'N', app()->getLocale() === 'en' ? $l[1] : $l[0]);
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** "Not visited for 30 days" */
    public function audienceLabel(): string
    {
        return self::segmentLabel($this->segment, $this->segment_value !== null ? money($this->segment_value) : null);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Orders that used this campaign's coupon (cancelled ones excluded). */
    public function redemptions(): int
    {
        return $this->coupon_id ? Order::query()->where('coupon_id', $this->coupon_id)->where('status', '!=', 'cancelled')->count() : 0;
    }
}
