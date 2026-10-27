<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One guest of a manual campaign (App\Services\Customers\Outreach): the staff tap «ابعت» and WhatsApp opens on their
 * own phone with the personalised message, or the configured SMS provider sends it. Carries the guest's personal
 * coupon, the ref of their link (orders.campaign_recipient_id) and, when asked for, their account-claim link.
 */
class CampaignRecipient extends TenantModel
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'sms_sent_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'claim_link' => 'encrypted',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** A short unique ref for the personal link (?ref=). */
    public static function newRef(): string
    {
        do {
            $ref = random_token(5);
        } while (self::query()->withoutGlobalScopes()->where('ref', $ref)->exists());

        return $ref;
    }
}
