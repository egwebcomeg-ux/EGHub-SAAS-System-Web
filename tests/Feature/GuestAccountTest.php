<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\SignIn\SignInGuard;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * "حسابي": the guest's account page per restaurant (/me/{card token}), the ways a new phone gets in (the link, or
 * mobile + a delivered order's number, throttled), the phone remembering the guest, and the dashboard's link card.
 */
class GuestAccountTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    private function guest(Tenant $tenant, string $phone = self::PHONE, string $name = 'Mona', array $extra = []): Customer
    {
        return $this->as($tenant, fn () => Customer::query()->create(['phone' => $phone, 'name' => $name] + $extra));
    }

    private function placedOrder(Tenant $tenant, ?Customer $customer, array $attributes = []): Order
    {
        return $this->as($tenant, function () use ($customer, $attributes) {
            $item = MenuItem::query()->first() ?? MenuItem::query()->create([
                'category_id' => Category::query()->firstOrCreate(['name' => 'Coffee'])->id, 'name' => 'Latte', 'price' => 65,
            ]);
            $order = Order::query()->create($attributes + [
                'branch_id' => Branch::query()->value('id'), 'number' => (int) Order::query()->max('number') + 1, 'type' => 'takeaway',
                'status' => 'served', 'source' => 'online', 'subtotal' => 130, 'total' => 130, 'tracking_token' => random_token(12),
                'customer_id' => $customer?->id, 'customer_phone' => (string) $customer?->phone,
            ]);
            OrderItem::query()->create(['order_id' => $order->id, 'menu_item_id' => $item->id, 'name' => $item->name, 'unit_price' => 65, 'quantity' => 2, 'line_total' => 130]);

            return $order;
        });
    }

    private function verify(string $slug, string $phone, string $number, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post($this->subUrl($slug, 'me/verify/order'), ['phone' => $phone, 'number' => $number]);
    }

    public function test_the_account_page_shows_only_this_guests_orders_and_is_private(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $sara = $this->guest($a, '01099999999', 'Sara');
        $mine = $this->placedOrder($a, $mona, ['number' => 501]);
        $this->placedOrder($a, $sara, ['number' => 777]);

        $page = $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertOk()
            ->assertSee('Mona')->assertSee('010•••••678')->assertDontSee('>'.self::PHONE.'<', false)
            ->assertSee('data-account-order="501"', false)->assertDontSee('data-account-order="777"', false)->assertDontSee('Sara')
            ->assertSee('2× Latte')
            // "order again" reuses the menu's ?reorder={tracking token}, and the order's tracking page is one tap away
            ->assertSee($this->subUrl('cafe-a').'?reorder='.$mine->tracking_token, false)
            ->assertSee($this->subUrl('cafe-a', 'o/'.$mine->tracking_token), false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertCookie('card_cafe-a', $mona->card_token);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
        $this->assertTrue($page->headers->getCookies()[0]->isHttpOnly());
        $this->assertSame('lax', $page->headers->getCookies()[0]->getSameSite());
    }

    public function test_a_token_of_another_restaurant_or_an_unknown_token_is_not_found(): void
    {
        $this->restaurant('cafe-a');
        $mona = $this->guest($this->restaurant('cafe-b'));

        $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertNotFound()->assertCookieMissing('card_cafe-a');
        $this->get($this->subUrl('cafe-a', 'me/'.str_repeat('a', 24)))->assertNotFound();
        $this->post($this->subUrl('cafe-a', "me/{$mona->card_token}/addresses/".str_repeat('a', 16).'/delete'))->assertNotFound();
        $this->get($this->subUrl('cafe-b', "me/{$mona->card_token}"))->assertOk();
    }

    public function test_the_dine_in_total_stays_hidden_when_the_restaurant_hides_it(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $this->placedOrder($a, $mona, ['type' => 'dine_in', 'source' => 'qr', 'number' => 10, 'total' => 333]);
        $this->placedOrder($a, $mona, ['type' => 'takeaway', 'number' => 11, 'total' => 444]);

        // the visible text only: a random tracking token in a link may hold the same digits
        $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertSeeText('333')->assertSeeText('444');

        $this->as($a, fn () => Settings::put(['dine_in_hide_total' => '1']));
        $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertDontSeeText('333')->assertSeeText('444');
    }

    public function test_saved_addresses_come_from_past_delivery_orders_and_only_this_guest_can_remove_one(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $sara = $this->guest($a, '01099999999', 'Sara', ['addresses' => [['zone_id' => 1, 'text' => 'Sara street']]]);
        $zone = $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'Maadi', 'fee' => 20, 'min_order' => 0]));
        $this->placedOrder($a, $mona, ['type' => 'delivery', 'delivery_zone_id' => $zone->id, 'delivery_address' => ['zone' => 'Maadi', 'text' => '9 Road 233', 'lat' => 29.96, 'lng' => 31.25]]);

        $page = $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertOk()->assertSee('9 Road 233')->assertSee('Maadi')
            ->assertSee('google.com/maps?q=29.96,31.25', false)->assertDontSee('Sara street');
        preg_match('/data-account-address="([a-f0-9]{16})"/', (string) $page->getContent(), $m);
        $saraKey = $this->as($a, fn () => GuestAccount::addresses($sara->fresh())[0]['key']);

        // Sara's address key on Mona's account removes nothing of Sara's
        $this->post($this->subUrl('cafe-a', "me/{$mona->card_token}/addresses/$saraKey/delete"))->assertRedirect();
        $this->assertCount(1, $this->as($a, fn () => $sara->fresh()->addresses));

        $this->post($this->subUrl('cafe-a', "me/{$mona->card_token}/addresses/{$m[1]}/delete"))->assertRedirect();
        $this->assertSame([], $this->as($a, fn () => $mona->fresh()->addresses));
        // removed for good: the past order does not bring it back
        $this->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertDontSee('9 Road 233');
    }

    public function test_mobile_and_a_delivered_order_number_open_the_account_and_remember_the_phone(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $order = $this->placedOrder($a, $mona, ['number' => 1234]);

        $this->verify('cafe-a', '+20 101 234 5678', '#'.$order->number)
            ->assertRedirect($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertCookie('card_cafe-a', $mona->card_token);

        // the menu then shows "حسابي" to the account and fills the cart with the guest's name and mobile
        $menu = $this->withCookie('card_cafe-a', $mona->card_token)->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-account-entry="remembered"', false)->assertSee($this->subUrl('cafe-a', "me/{$mona->card_token}"), false)
            ->assertDontSee('data-account-sheet', false);
        preg_match('/<script[^>]*id="menu-data"[^>]*>(.*?)<\/script>/s', (string) $menu->getContent(), $json);
        $this->assertSame(['name' => 'Mona', 'phone' => self::PHONE], json_decode($json[1], true)['account']);

        $audit = $this->as($a, fn () => AuditLog::query()->where('action', SignInGuard::AUDIT_ACTION)->first());
        $this->assertSame(['order_number' => ['ok' => 1]], $audit->meta);
        $this->assertSame('', $audit->ip);
    }

    public function test_every_wrong_combination_gets_the_same_answer_and_opens_nothing(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $mona = $this->guest($a);
        $sara = $this->guest($a, '01099999999', 'Sara');
        $served = $this->placedOrder($a, $mona, ['number' => 50]);
        $this->placedOrder($a, $sara, ['number' => 51]);
        $this->placedOrder($a, $mona, ['number' => 52, 'status' => 'new']);           // not delivered yet (could be planted)
        $this->placedOrder($a, $mona, ['number' => 53, 'created_at' => now()->subDays(91)]);
        $this->placedOrder($b, $this->guest($b), ['number' => 60]);                   // the same mobile at another restaurant

        $generic = __('We could not open an account with this mobile and order number. Check both, or ask the restaurant for your account link.');
        foreach ([
            ['01077777777', (string) $served->number],   // unknown mobile, real order number
            [self::PHONE, '51'],                          // someone else's order
            [self::PHONE, '52'], [self::PHONE, '53'],
            [self::PHONE, '60'],                          // another restaurant's order
        ] as $i => [$phone, $number]) {
            $this->verify('cafe-a', $phone, $number, "10.0.1.$i")->assertRedirect($this->subUrl('cafe-a', 'me'))
                ->assertSessionHasErrors(['phone' => $generic], errorBag: 'verify')->assertCookieMissing('card_cafe-a');
        }

        // a guest with money on the wallet opens only from their own link
        $this->as($a, fn () => $mona->forceFill(['credit_balance' => 40])->save());
        $this->verify('cafe-a', self::PHONE, '50', '10.0.2.1')->assertSessionHasErrors(['phone' => $generic], errorBag: 'verify');
    }

    public function test_a_mobile_is_locked_for_an_hour_after_five_wrong_numbers_and_an_ip_after_twenty_tries(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $order = $this->placedOrder($a, $mona, ['number' => 900]);

        for ($i = 0; $i < 5; $i++) {
            $this->verify('cafe-a', self::PHONE, (string) (100 + $i), "10.0.3.$i");
        }
        // the right number no longer opens it while locked, and an unknown mobile gets locked the same way
        $this->verify('cafe-a', self::PHONE, (string) $order->number, '10.0.3.9')
            ->assertSessionHasErrors(['phone' => __('Too many tries. Please try again in an hour.')], errorBag: 'verify')->assertCookieMissing('card_cafe-a');

        $this->travel(61)->minutes();
        $this->verify('cafe-a', self::PHONE, (string) $order->number, '10.0.3.10')->assertCookie('card_cafe-a', $mona->card_token);

        // one IP: 20 tries an hour, whatever the mobiles (spaced out, under the route's 10 a minute)
        for ($i = 0; $i < 20; $i++) {
            $this->travel(7)->seconds();
            $this->verify('cafe-a', '0101111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), '1', '10.0.4.1');
        }
        $this->verify('cafe-a', self::PHONE, (string) $order->number, '10.0.4.1')
            ->assertSessionHasErrors(['phone' => __('Too many tries. Please try again in an hour.')], errorBag: 'verify');

        $counts = $this->as($a, fn () => AuditLog::query()->where('action', SignInGuard::AUDIT_ACTION)->get()->pluck('meta'));
        $this->assertSame(25, $counts->sum(fn ($meta) => $meta['order_number']['failed'] ?? 0));
    }

    public function test_the_pasted_link_opens_the_account_of_this_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $other = $this->guest($this->restaurant('cafe-b'));

        $this->get($this->subUrl('cafe-a', 'me/open?link='.urlencode("https://egmenu.app/cafe-a/me/{$mona->card_token}")))
            ->assertRedirect($this->subUrl('cafe-a', "me/{$mona->card_token}"));
        $this->get($this->subUrl('cafe-a', 'me/open?link='.urlencode("https://egmenu.app/cafe-b/me/{$other->card_token}")))
            ->assertRedirect($this->subUrl('cafe-a', 'me'))->assertSessionHasErrors('link', errorBag: 'link');
    }

    public function test_the_menu_offers_the_sign_in_sheet_and_signing_out_forgets_the_phone(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $mona = $this->guest($a);

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-account-entry="sheet"', false)
            ->assertSee('data-account-sheet', false)->assertSee('data-account-verify', false);

        $this->withCookie('card_cafe-a', $mona->card_token)->post($this->subUrl('cafe-a', "me/{$mona->card_token}/sign-out"))
            ->assertRedirect($this->subUrl('cafe-a').'?signed_out=1')->assertCookieExpired('card_cafe-a');
    }

    public function test_a_new_guest_gets_the_account_link_after_ordering_but_typing_an_existing_mobile_does_not(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $item = $this->item($a, 'Latte', 60);
        $order = fn (string $phone) => $this->postJson($this->subUrl('cafe-a', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]],
            'customer_name' => 'Mona', 'customer_phone' => $phone, 'type' => 'takeaway',
        ])->assertOk();

        $first = $order(self::PHONE);
        $token = $this->as($a, fn () => Customer::query()->where('phone', self::PHONE)->value('card_token'));
        $first->assertCookie('card_cafe-a', $token);
        $this->withCookie('card_cafe-a', $token)->get($first->json('url'))->assertOk()
            ->assertSee('data-account-card', false)->assertSee($this->subUrl('cafe-a', "me/$token"), false)
            ->assertSee('wa.me/201012345678?text=', false);

        // someone else typing the same mobile on another phone: no cookie, no link, only how to get in
        $this->flushHeaders();
        $this->defaultCookies = [];
        $second = $order(self::PHONE)->assertCookieMissing('card_cafe-a');
        $this->get($second->json('url'))->assertOk()->assertSee('data-account-card-hint', false)->assertDontSee("me/$token", false);
    }

    public function test_the_loyalty_card_join_no_longer_hands_out_the_key_of_a_guest_who_ordered(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => LoyaltyProgram::query()->create([
            'title' => 'Coffee card', 'type' => 'visits', 'stamps_required' => 10, 'reward_label' => 'Free coffee', 'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup'],
        ]));
        $this->placedOrder($a, $mona = $this->guest($a));

        $this->post($this->subUrl('cafe-a', 'card'), ['phone' => self::PHONE])->assertSessionHasErrors('phone')->assertCookieMissing('card_cafe-a');
        $this->post($this->subUrl('cafe-a', 'card'), ['phone' => '01055555555'])->assertRedirectContains('/card/');
        $this->assertNotNull($mona);
    }

    public function test_the_dashboard_shows_the_link_and_a_new_link_closes_the_old_one(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        $old = $mona->card_token;

        $this->actingAs($this->owner($a))->get($this->dashUrl("/customers/{$mona->id}"))->assertOk()
            ->assertSee('data-account-link-card', false)->assertSee("cafe-a/me/$old", false)->assertSee('wa.me/201012345678?text=', false);

        $this->actingAs($this->owner($a))->post($this->dashUrl("/customers/{$mona->id}/account-link"))->assertRedirect();
        $new = $this->as($a, fn () => $mona->fresh()->card_token);
        $this->assertNotSame($old, $new);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $new);

        $this->get($this->subUrl('cafe-a', "me/$old"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "card/$old"))->assertNotFound();
        // a phone that remembered the old key is no longer signed in
        $this->withCookie('card_cafe-a', $old)->get($this->subUrl('cafe-a', 'me'))->assertOk()->assertSee('data-account-verify', false);
        $this->get($this->subUrl('cafe-a', "me/$new"))->assertOk();

        // another restaurant's guest cannot be rotated from here
        $other = $this->guest($this->restaurant('cafe-b'), '01099999999');
        $this->actingAs($this->owner($a))->post($this->dashUrl("/customers/{$other->id}/account-link"))->assertNotFound();
    }
}
