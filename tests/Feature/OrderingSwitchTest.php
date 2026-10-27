<?php

namespace Tests\Feature;

use App\Services\Settings;
use Tests\TenantTestCase;

/** «استقبال الطلبات» on the dashboard home: the owner turns ordering off (menu only shows prices) and on again. */
class OrderingSwitchTest extends TenantTestCase
{
    public function test_the_owner_turns_ordering_off_and_on_from_the_home_page(): void
    {
        $tenant = $this->restaurant('cafe-switch');
        $this->item($tenant);
        $owner = $this->owner($tenant);

        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-ordering-switch="on"', false);

        $this->actingAs($owner)->post($this->dashUrl('/settings/ordering'), ['on' => '0'])->assertRedirect()->assertSessionHas('ok');
        $this->assertSame('0', $this->as($tenant, fn () => Settings::get('ordering_enabled')));
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-ordering-switch="off"', false);
        $this->get($this->subUrl('cafe-switch'))->assertOk()->assertDontSee('data-online>', false);

        $this->actingAs($owner)->post($this->dashUrl('/settings/ordering'), ['on' => '1'])->assertRedirect();
        $this->assertSame('1', $this->as($tenant, fn () => Settings::get('ordering_enabled')));
    }
}
