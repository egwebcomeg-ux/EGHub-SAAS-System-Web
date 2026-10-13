<?php

namespace App\Services\Orders;

/** An order the guest or staff can't place/change; the message is shown to them as is. */
final class OrderException extends \RuntimeException
{
}
