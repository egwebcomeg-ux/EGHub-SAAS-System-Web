<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket of the standalone pager station (App\Services\Orders\Pager): an order taken on another POS, known here by
 * its number only. The guest opens /p/{token} from the ticket's QR and their phone rings when staff call.
 * Status: waiting → called → picked_up, or expired when nobody closed it in time.
 */
class PagerTicket extends TenantModel
{
    public const STATUSES = ['waiting', 'called', 'picked_up', 'expired'];

    /** Statuses after which the ticket is done: no more calls, the guest page stops polling. */
    public const CLOSED = ['picked_up', 'expired'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'call_count' => 'integer',
            'service_date' => 'date',
            'first_called_at' => 'datetime',
            'called_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
