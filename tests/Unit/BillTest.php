<?php

namespace Tests\Unit;

use App\Services\Orders\Bill;
use PHPUnit\Framework\TestCase;

/** The one rule every bill follows: service in the hall on the discounted food, tax on food plus service. */
class BillTest extends TestCase
{
    public function test_service_is_taken_in_the_hall_only_and_tax_on_food_plus_service(): void
    {
        $this->assertSame(['service' => 12.0, 'tax' => 15.68], Bill::charges(100, true, 0.12, 0.14));
        $this->assertSame(['service' => 0.0, 'tax' => 14.0], Bill::charges(100, false, 0.12, 0.14));
    }

    public function test_charges_are_rounded_to_piasters(): void
    {
        $this->assertSame(['service' => 4.0, 'tax' => 5.23], Bill::charges(33.33, true, 0.12, 0.14));
    }
}
