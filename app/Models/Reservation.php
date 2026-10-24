<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table reservation. Status flow: pending → confirmed → seated, and pending/confirmed → cancelled | no_show.
 * Pending, confirmed and seated bookings hold seats (App\Services\Reservations\ReservationService::seatsLeft()).
 */
class Reservation extends TenantModel
{
    public const STATUSES = [
        'pending' => ['مستني التأكيد', 'Pending'],
        'confirmed' => ['متأكد', 'Confirmed'],
        'seated' => ['وصل وقعد', 'Seated'],
        'cancelled' => ['ملغي', 'Cancelled'],
        'no_show' => ['ماجاش', 'No-show'],
    ];

    public const TRANSITIONS = [
        'pending' => ['confirmed', 'seated', 'cancelled', 'no_show'],
        'confirmed' => ['seated', 'cancelled', 'no_show'],
        'seated' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    /** Statuses that hold seats. */
    public const ACTIVE = ['pending', 'confirmed', 'seated'];

    /** Column stamped when a booking enters a status. */
    public const STAMPS = ['confirmed' => 'confirmed_at', 'seated' => 'seated_at', 'cancelled' => 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'party_size' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** The guest may cancel a booking that is still ahead of them. */
    public function guestCanCancel(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true) && $this->starts_at->isFuture();
    }

    public static function statusLabelOf(string $status): string
    {
        $l = self::STATUSES[$status] ?? [$status, $status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public function statusLabel(): string
    {
        return self::statusLabelOf($this->status);
    }

    public function url(): string
    {
        return tenant()->url('reserve/'.$this->token);
    }
}
