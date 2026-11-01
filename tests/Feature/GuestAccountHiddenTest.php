<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Customers\GuestAccount;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** A restaurant hides "حسابي": orders only, handed to its WhatsApp; other restaurants keep their accounts. */
class GuestAccountHiddenTest extends TenantTestCase
{
    private const FORM = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
        'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    private function restaurantWith(string $slug, array $settings = []): Tenant
    {
        $tenant = $this->restaurant($slug, 'pro');
        $this->item($tenant, 'Latte', 60);
        $this->as($tenant, fn () => Settings::put($settings + ['whatsapp' => '01099999999', 'online_takeaway' => '1']));

        return $tenant;
    }

    private function place(Tenant $tenant)
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());

        return $this->postJson($this->subUrl($tenant->id, 'order'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'qty' => 1]],
            'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ]);
    }

    public function test_the_menu_of_a_hiding_restaurant_has_no_account_and_another_restaurant_keeps_it(): void
    {
        $this->restaurantWith('cafe-a', ['guest_account_hidden' => '1']);
        $this->restaurantWith('cafe-b');

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-account-entry', false)->assertDontSee('data-account-sheet', false);
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertSee('data-account-entry', false)->assertSee('data-account-sheet', false);
    }

    public function test_a_remembered_guest_is_not_shown_an_account_while_it_is_hidden(): void
    {
        $a = $this->restaurantWith('cafe-a', ['guest_account_hidden' => '1']);
        $token = $this->as($a, fn () => GuestAccount::token(Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona', 'source' => 'menu'])));

        $this->withCookie('card_cafe-a', $token)->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-account-entry', false);
    }

    public function test_the_account_pages_are_closed_while_hidden_and_open_again_after(): void
    {
        $a = $this->restaurantWith('cafe-a', ['guest_account_hidden' => '1']);

        $this->get($this->subUrl('cafe-a', 'me'))->assertRedirect();
        $this->postJson($this->subUrl('cafe-a', 'me/verify/order'), ['phone' => '01012345678', 'order' => '1'])->assertNotFound();
        $this->postJson($this->subUrl('cafe-a', 'me/phone-code'), ['phone' => '01012345678'])->assertNotFound();

        $this->as($a, fn () => Settings::put(['guest_account_hidden' => '0']));
        $this->get($this->subUrl('cafe-a', 'me'))->assertOk();
    }

    public function test_every_guest_order_goes_to_whatsapp_with_no_account_cookie_and_no_mobile_check(): void
    {
        // the handoff switch stays off and the mobile check is on: hiding the account overrides both
        $a = $this->restaurantWith('cafe-a', ['guest_account_hidden' => '1', 'order_whatsapp_handoff' => '0', 'online_verify_phone' => 'all']);
        config(['sms.driver' => 'log']);

        $response = $this->place($a)->assertOk()->assertCookieMissing('card_cafe-a');

        $this->assertStringContainsString('wa=1', $response->json('url'));
        $this->get($response->json('url'))->assertOk()->assertSee('https://wa.me/201099999999', false)
            ->assertSee('data-whatsapp-order-auto', false)->assertDontSee('data-account-card', false);
        $this->assertSame(1, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_the_owner_chooses_how_the_sign_in_code_is_sent_and_email_is_the_default(): void
    {
        $a = $this->restaurantWith('cafe-a');
        $owner = $this->owner($a);
        $this->assertSame('email', $this->as($a, fn () => Settings::get('guest_code_channel')));

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1', 'guest_code_channel' => 'whatsapp'])->assertSessionHasNoErrors();
        $this->assertSame('whatsapp', $this->as($a, fn () => Settings::get('guest_code_channel')));

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1', 'guest_code_channel' => 'anything'])->assertSessionHasNoErrors();
        $this->assertSame('email', $this->as($a, fn () => Settings::get('guest_code_channel')));
    }

    public function test_the_owner_switches_it_from_the_orders_settings(): void
    {
        $a = $this->restaurantWith('cafe-a');
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/settings?tab=orders'))->assertOk()->assertSee('name="guest_account_hidden"', false);

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1', 'guest_account_hidden' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('guest_account_hidden')));

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['whatsapp_handoff_sent' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($a, fn () => Settings::get('guest_account_hidden')));
    }
}
