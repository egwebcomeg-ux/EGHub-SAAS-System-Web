<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WhatsApp message to a guest: queued, then sent (1 credit) or failed (error = no_credits or the provider's reason). */
class WhatsAppMessage extends TenantModel
{
    public const NO_CREDITS = 'no_credits';

    protected $table = 'whatsapp_messages';

    protected function casts(): array
    {
        return ['params' => 'array', 'sent_at' => 'datetime', 'cost_credits' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
