<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Tenant;
use Tests\TenantTestCase;

/** One coupons page for every discount code: campaign coupons and loyalty vouchers, filters, rights, isolation. */
class CouponsPageTest extends TenantTestCase
{
    /** @param array<string, mixed> $attrs */
    private function coupon(Tenant $t, string $code, array $attrs = []): Coupon
    {
        return $this->as($t, fn () => Coupon::query()->create($attrs + [
            'code' => $code, 'source' => 'voucher', 'type' => 'percent', 'value' => 10, 'once_per_customer' => false,
        ]));
    }

    /** Pro (loyalty card) plus the marketing pack (campaigns), with one code of every kind. */
    private function restaurantWithCodes(): Tenant
    {
        $a = $this->restaurant('cafe-a');
        app(Billing::class)->setAddon($a, 'marketing_pack', 1);
        $mona = $this->as($a, fn () => Customer::query()->create(['phone' => '01011112222', 'name' => 'Mona']));
        $this->coupon($a, 'CAMP20', ['source' => 'campaign']);
        $this->coupon($a, 'PUBLIC10');
        $this->coupon($a, 'MONA50', ['customer_id' => $mona->id]);
        $this->coupon($a, 'RMD-AAAA', ['source' => 'batch', 'batch' => 'Ramadan', 'max_uses' => 1]);
        $this->coupon($a, 'OLD5', ['expires_at' => now()->subDay()]);

        return $a;
    }

    public function test_every_code_is_listed_with_its_type_and_the_filters_narrow_the_list(): void
    {
        $a = $this->restaurantWithCodes();
        $owner = $this->owner($a);

        $page = $this->actingAs($owner)->get($this->dashUrl('/coupons'))->assertOk()
            ->assertSee('data-voucher-form', false)->assertSee('data-batch-form', false)->assertSee('data-give-form', false);
        foreach (['CAMP20' => 'campaign', 'PUBLIC10' => 'public', 'MONA50' => 'guest', 'RMD-AAAA' => 'batch', 'OLD5' => 'public'] as $code => $kind) {
            $page->assertSee('data-voucher="'.$code.'" data-coupon-kind="'.$kind.'"', false);
        }

        $this->actingAs($owner)->get($this->dashUrl('/coupons?kind=campaign'))->assertOk()
            ->assertSee('data-voucher="CAMP20"', false)->assertDontSee('data-voucher="PUBLIC10"', false)->assertDontSee('data-voucher="MONA50"', false);
        $this->actingAs($owner)->get($this->dashUrl('/coupons?kind=guest'))->assertOk()
            ->assertSee('data-voucher="MONA50"', false)->assertDontSee('data-voucher="PUBLIC10"', false);
        $this->actingAs($owner)->get($this->dashUrl('/coupons?status=expired'))->assertOk()
            ->assertSee('data-voucher="OLD5"', false)->assertDontSee('data-voucher="PUBLIC10"', false);
        $this->actingAs($owner)->get($this->dashUrl('/coupons?q=01011112222'))->assertOk()
            ->assertSee('data-voucher="MONA50"', false)->assertDontSee('data-voucher="CAMP20"', false);
        $this->actingAs($owner)->get($this->dashUrl('/coupons?batch=Ramadan'))->assertOk()
            ->assertSee('data-voucher="RMD-AAAA"', false)->assertDontSee('data-voucher="PUBLIC10"', false);

        // pause and resume a campaign coupon from here
        $camp = $this->as($a, fn () => Coupon::query()->where('code', 'CAMP20')->sole());
        $this->actingAs($owner)->post($this->dashUrl("/coupons/{$camp->id}/toggle"))->assertRedirect();
        $this->assertFalse($camp->fresh()->is_active);
        $this->actingAs($owner)->post($this->dashUrl("/coupons/{$camp->id}/toggle"));
        $this->assertTrue($camp->fresh()->is_active);
    }

    public function test_each_side_shows_only_to_whoever_runs_it_today(): void
    {
        // loyalty card without campaigns: vouchers only
        $a = $this->restaurant('cafe-a');
        $this->coupon($a, 'CAMP20', ['source' => 'campaign']);
        $this->coupon($a, 'PUBLIC10');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/coupons'))->assertOk()
            ->assertSee('data-voucher="PUBLIC10"', false)->assertDontSee('data-voucher="CAMP20"', false);
        $camp = $this->as($a, fn () => Coupon::query()->where('code', 'CAMP20')->sole());
        $this->actingAs($owner)->post($this->dashUrl("/coupons/{$camp->id}/toggle"))->assertForbidden();

        // a cashier runs neither side; a plan with neither feature has no page
        $this->actingAs($this->staff($a, 'cashier', 'c@cafe-a.test'))->get($this->dashUrl('/coupons'))->assertForbidden();
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/coupons'))->assertForbidden();
    }

    public function test_giving_a_voucher_to_a_guest_needs_vouchers_give(): void
    {
        $a = $this->restaurant('cafe-a');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->actingAs($manager)->post($this->dashUrl('/coupons/give'), ['phone' => '01011112222', 'name' => 'Mona', 'type' => 'amount', 'value' => 30, 'days' => 7])
            ->assertRedirect()->assertSessionHasNoErrors();
        $given = $this->as($a, fn () => Coupon::query()->with('customer')->sole());
        $this->assertSame('01011112222', $given->customer->phone);
        $this->assertSame(1, $given->max_uses);
        $this->assertSame('guest', $given->kind());

        // a manager's rights without vouchers.give: the page opens, but no form and the request is refused
        $limited = $this->staff($a, 'manager', 'm2@cafe-a.test');
        $this->as($a, function () use ($limited) {
            $rights = $limited->getAllPermissions()->pluck('name')->reject(fn (string $p) => $p === 'vouchers.give')->values()->all();
            $limited->syncRoles([]);
            $limited->syncPermissions($rights);
        });
        $this->actingAs($limited->fresh())->get($this->dashUrl('/coupons'))->assertOk()->assertDontSee('data-give-form', false);
        $this->actingAs($limited->fresh())->post($this->dashUrl('/coupons/give'), ['phone' => '01033334444', 'type' => 'amount', 'value' => 30, 'days' => 7])
            ->assertForbidden();
        $this->assertSame(1, $this->as($a, fn () => Coupon::query()->count()));
    }

    public function test_another_restaurants_codes_are_invisible_and_untouchable(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $theirs = $this->coupon($b, 'BONLY');
        $this->coupon($a, 'AONLY');

        $this->actingAs($this->owner($a))->get($this->dashUrl('/coupons?q=only'))->assertOk()
            ->assertSee('data-voucher="AONLY"', false)->assertDontSee('BONLY');
        $this->actingAs($this->owner($a))->post($this->dashUrl("/coupons/{$theirs->id}/toggle"))->assertNotFound();
        $this->assertTrue($theirs->fresh()->is_active);
    }

    public function test_the_old_vouchers_address_lands_on_the_coupons_page_and_campaigns_pick_from_the_list(): void
    {
        $a = $this->restaurant('cafe-a');
        app(Billing::class)->setAddon($a, 'marketing_pack', 1);
        $owner = $this->owner($a);
        $public = $this->coupon($a, 'PUBLIC10');
        $guest = $this->coupon($a, 'MONA50', ['customer_id' => $this->as($a, fn () => Customer::query()->create(['phone' => '01011112222']))->id]);

        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers?status=active&batch=Ramadan'))
            ->assertRedirect(route('dash.coupons', ['status' => 'active', 'batch' => 'Ramadan']));

        // the campaign form offers the public code, not the guest's
        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()
            ->assertSee('data-code="PUBLIC10"', false)->assertDontSee('data-code="MONA50"', false);

        $this->as($a, fn () => Customer::query()->create(['phone' => '01099990000', 'name' => 'Omar']));
        $send = ['name' => 'Back', 'segment' => 'all', 'message' => 'Hi {name}', 'when' => 'later', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i')];
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send + ['coupon_id' => $guest->id])->assertSessionHasErrors('coupon_id');
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send + ['coupon_id' => $public->id])->assertSessionHasNoErrors();
        $this->assertSame($public->id, $this->as($a, fn () => Campaign::query()->sole()->coupon_id));
        $this->assertSame(2, $this->as($a, fn () => Coupon::query()->count()));   // no new code was made
    }
}
