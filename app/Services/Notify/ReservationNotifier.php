<?php

namespace App\Services\Notify;

/**
 * Called by the reservations module when a booking is confirmed (inside the restaurant's tenant context).
 * Resolve it from the container: app(ReservationNotifier::class)->confirmed([...]).
 * Bound to WhatsAppReservationNotifier (a no-op unless WhatsApp notifications are on); NullReservationNotifier
 * can be bound instead to switch it off.
 */
interface ReservationNotifier
{
    /**
     * $reservation: phone (the guest's mobile, required), name, at (date and time of the booking), guests, customer_id.
     *
     * @param  array{phone: string, name?: string, at?: string|\DateTimeInterface, guests?: int, customer_id?: int|null}  $reservation
     */
    public function confirmed(array $reservation): void;
}
