<?php

namespace App\Services\Reservations;

/** A booking that can't be made or moved; the message is shown to the guest / staff as is. */
class ReservationException extends \RuntimeException {}
