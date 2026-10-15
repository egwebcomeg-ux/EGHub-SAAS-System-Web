<?php

namespace App\Services\Notify;

use App\Models\Reservation;

/** "Your table is confirmed" on WhatsApp, once, when the booking becomes confirmed (see GuestNotifier). */
final class WhatsAppReservationNotifier implements ReservationNotifier
{
    public function __construct(private GuestNotifier $notifier) {}

    public function created(Reservation $reservation): void
    {
        if ($reservation->status === 'confirmed') {
            $this->confirmed($reservation);
        }
    }

    public function statusChanged(Reservation $reservation, string $from): void
    {
        if ($reservation->status === 'confirmed' && $from !== 'confirmed') {
            $this->confirmed($reservation);
        }
    }

    private function confirmed(Reservation $r): void
    {
        $this->notifier->reservationConfirmed([
            'phone' => $r->phone, 'name' => $r->name, 'at' => $r->starts_at, 'guests' => $r->party_size, 'customer_id' => $r->customer_id,
        ]);
    }
}
