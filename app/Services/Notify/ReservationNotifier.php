<?php

namespace App\Services\Notify;

use App\Models\Reservation;

/**
 * Tells the guest about a reservation: booked, confirmed, cancelled... Called by the reservations module inside the
 * restaurant's tenant context. Bound in AppServiceProvider to WhatsAppReservationNotifier (a no-op unless the
 * restaurant switched WhatsApp notifications on); LogReservationNotifier / NullReservationNotifier can be bound instead.
 */
interface ReservationNotifier
{
    /** A new booking was made (by the guest from the menu link or by staff). */
    public function created(Reservation $reservation): void;

    /** The booking moved from $from to its current status (confirmed, seated, cancelled, no_show). */
    public function statusChanged(Reservation $reservation, string $from): void;
}
