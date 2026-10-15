<?php

namespace App\Services\Notify;

use App\Models\Reservation;
use Illuminate\Support\Facades\Log;

/** Debug ReservationNotifier: writes the booking events to the log instead of messaging the guest (bind it by hand). */
class LogReservationNotifier implements ReservationNotifier
{
    public function created(Reservation $reservation): void
    {
        Log::info('reservation created', $this->context($reservation));
    }

    public function statusChanged(Reservation $reservation, string $from): void
    {
        Log::info('reservation status changed', $this->context($reservation) + ['from' => $from]);
    }

    /** @return array<string,mixed> */
    private function context(Reservation $r): array
    {
        return [
            'tenant' => $r->tenant_id, 'reservation' => $r->id, 'branch' => $r->branch_id, 'status' => $r->status,
            'starts_at' => $r->starts_at?->toDateTimeString(), 'party' => $r->party_size, 'source' => $r->source,
        ];
    }
}
