<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\User;
use Tests\TenantTestCase;

/** One restaurant must never see, change or collide with another restaurant's data. */
class TenantIsolationTest extends TenantTestCase
{
    public function test_models_are_scoped_to_the_current_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->item($a, 'Latte A');
        $this->item($b, 'Latte B');

        $this->assertSame(['Latte A'], $this->as($a, fn () => MenuItem::query()->pluck('name')->all()));
        $this->assertSame(['Latte B'], $this->as($b, fn () => MenuItem::query()->pluck('name')->all()));
        $this->assertSame(1, $this->as($a, fn () => User::query()->count()));
        $this->assertSame(5, $this->as($a, fn () => DiningTable::query()->count()));
    }

    public function test_rows_get_the_current_tenant_id_and_cannot_be_created_outside_a_tenant(): void
    {
        $a = $this->restaurant('cafe-a');
        $customer = $this->as($a, fn () => Customer::query()->create(['phone' => '01000000001']));
        $this->assertSame('cafe-a', $customer->tenant_id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Customer::query()->create(['phone' => '01000000002']); // no tenant → tenant_id NOT NULL fails
    }

    public function test_same_phone_can_be_a_customer_of_two_restaurants(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->as($a, fn () => Customer::query()->create(['phone' => '01000000001']));
        $this->as($b, fn () => Customer::query()->create(['phone' => '01000000001']));
        $this->assertSame(2, Customer::query()->withoutGlobalScopes()->count());
    }

    public function test_public_menu_shows_only_its_own_items(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->item($a, 'Only-In-A');
        $this->item($b, 'Only-In-B');

        $this->get($this->menuUrl('cafe-a'))->assertOk()->assertSee('Only-In-A')->assertDontSee('Only-In-B');
        $this->get($this->menuUrl('cafe-b'))->assertOk()->assertSee('Only-In-B')->assertDontSee('Only-In-A');
    }

    public function test_table_qr_of_another_restaurant_is_not_found(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $tokenOfB = $this->as($b, fn () => DiningTable::query()->value('qr_token'));

        $this->get($this->menuUrl("cafe-a/t/$tokenOfB"))->assertNotFound();
        $this->get($this->menuUrl("cafe-b/t/$tokenOfB"))->assertRedirect($this->menuUrl('cafe-b'));
    }

    public function test_dashboard_cannot_reach_another_restaurants_user_by_id(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $userOfB = $this->owner($b);

        $this->actingAs($this->owner($a))
            ->post($this->dashUrl("/users/{$userOfB->id}"), ['name' => 'Hacked', 'email' => 'x@x.test', 'role' => 'owner'])
            ->assertNotFound();
        $this->assertNotSame('Hacked', $userOfB->fresh()->name);
    }

    public function test_dashboard_shows_the_logged_in_users_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');

        $this->actingAs($this->owner($a))->get($this->dashUrl())
            ->assertOk()->assertSee('Restaurant cafe-a')->assertDontSee('Restaurant cafe-b');
    }
}
