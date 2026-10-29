<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Services\Customers\Segments;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message to a group of the guest list. Sent via (channel): "whatsapp" from EgHub's number with credits, sent now or
 * at a time (App\Services\Marketing\Campaigns), or "manual", a send list the staff go down on their own phone, which
 * can also go out as SMS (App\Services\Customers\Outreach). `segment` is a guest group
 * (App\Services\Customers\Segments) or one of the FILTERS below.
 */
class Campaign extends TenantModel
{
    /** Who gets it besides the guest groups: key => [arabic, english, what segment_value means (null = none)] */
    public const FILTERS = [
        'all' => ['كل العملاء', 'All customers', null],
        'inactive' => ['مزاروش من :n يوم', 'Not visited for :n days', 'days'],
        'visits' => ['زاروا :n مرة أو أكتر', 'Visited :n times or more', 'visits'],
        'loyalty' => ['معاهم كارت ولاء', 'Loyalty card holders', null],
        'spent' => ['صرفوا :n جنيه أو أكتر', 'Spent :n EGP or more', 'amount'],
    ];

    /** How a campaign goes out: key => [arabic, english] */
    public const VIA = [
        'manual' => ['من موبايلي', 'From my phone'],
        'sms' => ['SMS', 'SMS'],
        'whatsapp' => ['واتساب EgHub', 'EgHub WhatsApp'],
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

    /** @return list<string> everything a campaign can go to: the guest groups, then the filters */
    public static function audiences(): array
    {
        return [...Segments::keys(), ...array_keys(self::FILTERS)];
    }

    /** What the number of a filter means ("days", "visits", "amount"), or null when the audience takes none. */
    public static function valueUnit(string $segment): ?string
    {
        return self::FILTERS[$segment][2] ?? null;
    }

    /** The audience's label with its number filled in (":n" → "N" when there is none yet). */
    public static function segmentLabel(string $segment, ?string $n = null): string
    {
        if (Segments::is($segment)) {
            return Segments::label($segment);
        }
        $l = self::FILTERS[$segment] ?? [$segment, $segment];

        return str_replace(':n', $n ?? 'N', app()->getLocale() === 'en' ? $l[1] : $l[0]);
    }

    /** whatsapp, manual, or sms (a manual send list that went out as SMS). */
    public function via(): string
    {
        return $this->channel === 'manual' && ($this->options['via'] ?? null) === 'sms' ? 'sms' : (string) $this->channel;
    }

    public static function viaLabel(string $via): string
    {
        $l = self::VIA[$via] ?? [$via, $via];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
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
