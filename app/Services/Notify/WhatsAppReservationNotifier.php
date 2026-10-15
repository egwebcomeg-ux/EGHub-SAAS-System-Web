<?php

namespace App\Services\Notify;

/** "Your table is confirmed" on WhatsApp, when the restaurant switched it on (see GuestNotifier). */
final class WhatsAppReservationNotifier implements ReservationNotifier
{
    public function __construct(private GuestNotifier $notifier) {}

    public function confirmed(array $reservation): void
    {
        $this->notifier->reservationConfirmed($reservation);
    }
}
