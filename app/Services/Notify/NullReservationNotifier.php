<?php

namespace App\Services\Notify;

/** Sends nothing. Bind it in place of WhatsAppReservationNotifier to switch reservation messages off entirely. */
final class NullReservationNotifier implements ReservationNotifier
{
    public function confirmed(array $reservation): void {}
}
