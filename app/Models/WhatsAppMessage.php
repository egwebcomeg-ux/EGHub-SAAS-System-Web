<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WhatsApp message to a guest: queued, then sent (1 credit) or failed (error = no_credits or the provider's reason). */
class WhatsAppMessage extends TenantModel
{
    public const NO_CREDITS = 'no_credits';

    /** Not sent and not charged: the EgHub number does not send for restaurants yet (config whatsapp.platform_sending). */
    public const PLATFORM_OFF = 'platform_off';

    /** Still queued: the provider could not be reached or asked for a pause, the message is sent again a little later. */
    public const RETRY_LATER = 'retry_later';

    protected $table = 'whatsapp_messages';

    protected function casts(): array
    {
        return ['params' => 'array', 'buttons' => 'array', 'sent_at' => 'datetime', 'cost_credits' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
