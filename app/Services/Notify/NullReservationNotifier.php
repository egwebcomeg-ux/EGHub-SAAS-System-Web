<?php

namespace App\Services\Notify;

use App\Models\Reservation;

/** Switches reservation messages off entirely (bind it instead of the WhatsApp one). */
final class NullReservationNotifier implements ReservationNotifier
{
    public function created(Reservation $reservation): void {}

    public function statusChanged(Reservation $reservation, string $from): void {}
}
