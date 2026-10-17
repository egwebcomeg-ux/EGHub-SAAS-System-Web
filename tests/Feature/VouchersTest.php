<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Discount vouchers of the loyalty card: single, held by a guest, printed batches, at the counter and the POS. */
class VouchersTest extends TenantTestCase
{
    /** @param array<string, mixed> $attrs */
    private function voucher(Tenant $t, array $attrs = []): Coupon
    {
        return $this->as($t, fn () => Coupon::query()->create($attrs + [
            'code' => 'GIFT20', 'source' => 'voucher', 'type' => 'percent', 'value' => 20, 'once_per_customer' => false,
        ]));
    }

    /**
     * Places an online order and returns it, or the refusal message.
     *
     * @param  array<int, array{0: MenuItem, 1: int}>  $lines
     */
    private function order(Tenant $t, array $lines, string $phone, string $code): Order|string
    {
        return $this->as($t, function () use ($lines, $phone, $code) {
            try {
                return app(OrderService::class)->place([
                    'items' => array_map(fn ($l) => ['item_id' => $l[0]->id, 'qty' => $l[1]], $lines),
                    'customer_phone' => $phone, 'coupon' => $code, 'source' => 'online',
                ], Branch::query()->first());
            } catch (OrderException $e) {
                return $e->getMessage();
            }
        });
    }

    public function test_the_owner_creates_pauses_and_prints_a_voucher(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Latte', 100);

        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers'), [
            'code' => 'welcome50', 'type' => 'amount', 'value' => 50, 'min_order' => 150, 'expires_at' => now()->addDays(10)->format('Y-m-d'), 'note' => 'Flyers',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers'), ['type' => 'percent', 'value' => 10])->assertSessionHasNoErrors();

        $vouchers = $this->as($a, fn () => Coupon::query()->orderBy('id')->get());
        $this->assertSame('WELCOME50', $vouchers[0]->code);
        $this->assertSame('voucher', $vouchers[0]->source);
        $this->assertMatchesRegularExpression('/^CAFEA-[2-9A-HJKMNP-Z]{4}$/', $vouchers[1]->code);
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers'))->assertOk()->assertSee('WELCOME50')->assertSee($vouchers[1]->code);

        $this->actingAs($owner)->get($this->dashUrl("/loyalty/vouchers/print?ids={$vouchers[0]->id}&layout=a4"))->assertOk()
            ->assertSee('data-print-voucher="WELCOME50"', false)->assertSee('<svg', false);

        $this->actingAs($owner)->post($this->dashUrl("/loyalty/vouchers/{$vouchers[0]->id}/toggle"))->assertRedirect();
        $this->assertFalse($vouchers[0]->fresh()->is_active);
        $this->assertIsString($this->order($a, [[$item, 2]], '01000000001', 'WELCOME50'));     // paused
        $this->actingAs($owner)->post($this->dashUrl("/loyalty/vouchers/{$vouchers[0]->id}/toggle"));
        $order = $this->order($a, [[$item, 2]], '01000000001', 'WELCOME50');
        $this->assertSame(150.0, $order->total);
        $this->assertSame(50.0, $order->coupon_discount);

        // its usage: one order, its sales and the discount it gave
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers?q=welcome'))->assertOk()
            ->assertSeeInOrder(['data-voucher="WELCOME50"', '<td>1</td>', '<td>150</td>', '<td>50</td>'], false)->assertDontSee($vouchers[1]->code);
    }

    public function test_a_batch_makes_unique_single_use_codes_with_a_csv_export(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers/batch'), [
            'count' => 501, 'type' => 'percent', 'value' => 15,
        ])->assertSessionHasErrors('count');
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers/batch'), [
            'count' => 12, 'batch' => 'Ramadan', 'prefix' => 'rmd', 'type' => 'percent', 'value' => 15, 'max_discount' => 40,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $codes = $this->as($a, fn () => Coupon::query()->where('batch', 'Ramadan')->get());
        $this->assertCount(12, $codes);
        $this->assertCount(12, $codes->pluck('code')->unique());
        $this->assertTrue($codes->every(fn (Coupon $c) => $c->max_uses === 1 && $c->source === 'batch' && str_starts_with($c->code, 'RMD-')));

        $csv = $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers/export?batch=Ramadan'))->assertOk()->streamedContent();
        $this->assertCount(13, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString($codes[0]->code, $csv);
        $this->assertStringContainsString('?coupon='.$codes[0]->code, $csv);

        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers/print?batch=Ramadan'))->assertOk()->assertSee($codes[11]->code);
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers/batch/toggle'), ['batch' => 'Ramadan', 'active' => 0])->assertRedirect();
        $this->assertSame(0, $this->as($a, fn () => Coupon::query()->where('is_active', true)->count()));
    }

    public function test_a_voucher_held_by_a_guest_only_works_with_their_mobile(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 100);
        $this->actingAs($this->owner($a))->post($this->dashUrl('/loyalty/vouchers'), [
            'code' => 'MONA', 'type' => 'percent', 'value' => 50, 'phone' => '01011112222', 'name' => 'Mona',
        ])->assertSessionHasNoErrors();
        $voucher = $this->as($a, fn () => Coupon::query()->with('customer')->sole());
        $this->assertSame('01011112222', $voucher->customer->phone);
        $this->assertSame(1, $voucher->max_uses);

        $this->assertSame(__('This voucher belongs to another guest.'), $this->order($a, [[$item, 1]], '01099998888', 'MONA'));
        $this->assertSame(__('Enter your mobile number to use this coupon.'), $this->order($a, [[$item, 1]], '', 'MONA'));
        $this->assertSame(50.0, $this->order($a, [[$item, 1]], '01011112222', 'MONA')->total);
        $this->assertIsString($this->order($a, [[$item, 1]], '01011112222', 'MONA')); // its one use is gone
    }

    public function test_start_date_expiry_minimum_order_uses_and_once_per_guest_are_enforced(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 100);
        $this->voucher($a, ['starts_at' => now()->addDays(2), 'expires_at' => now()->addDays(5), 'min_order' => 200, 'max_uses' => 2, 'once_per_customer' => true]);

        $this->assertStringContainsString(local_time(now()->addDays(2), 'Y/m/d'), $this->order($a, [[$item, 2]], '01000000001', 'gift20'));
        $this->travel(3)->days();
        $this->assertIsString($this->order($a, [[$item, 1]], '01000000001', 'gift20'));         // under the minimum
        $this->assertSame(160.0, $this->order($a, [[$item, 2]], '01000000001', 'gift20')->total);
        $this->assertSame(__('You already used this coupon.'), $this->order($a, [[$item, 2]], '01000000001', 'gift20'));
        $this->assertSame(160.0, $this->order($a, [[$item, 2]], '01000000002', 'gift20')->total);
        $this->assertSame(__('This coupon was already fully used.'), $this->order($a, [[$item, 2]], '01000000003', 'gift20'));

        $this->as($a, fn () => Coupon::query()->update(['max_uses' => null]));
        $this->travel(3)->days();
        $this->assertSame(__('This coupon code is not valid.'), $this->order($a, [[$item, 2]], '01000000004', 'gift20'));
    }

    public function test_a_voucher_for_some_items_takes_its_discount_off_those_lines_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 100);
        $cake = $this->as($a, function () {
            $desserts = Category::query()->create(['name' => 'Desserts']);

            return MenuItem::query()->create(['category_id' => $desserts->id, 'name' => 'Cake', 'price' => 60]);
        });
        $this->voucher($a, ['code' => 'SWEET', 'value' => 50, 'qualifying' => ['categories' => [$cake->category_id], 'items' => []]]);
        $this->voucher($a, ['code' => 'LATTE10', 'type' => 'amount', 'value' => 500, 'qualifying' => ['categories' => [], 'items' => [$latte->id]]]);

        $order = $this->order($a, [[$latte, 1], [$cake, 2]], '01000000001', 'SWEET');
        $this->assertSame(60.0, $order->discount);                                               // 50% of the two cakes (120)
        $this->assertSame(160.0, $order->total);
        $this->assertSame(100.0, $this->order($a, [[$latte, 1], [$cake, 1]], '01000000001', 'LATTE10')->discount); // capped at the latte line
        $this->assertStringContainsString('Desserts', $this->order($a, [[$latte, 2]], '01000000001', 'SWEET'));
    }

    public function test_the_counter_gives_a_voucher_and_the_guest_card_lists_only_their_own(): void
    {
        $a = $this->restaurant('cafe-a');
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        [$mona, $omar] = $this->as($a, fn () => [
            Customer::query()->create(['phone' => '01011112222', 'name' => 'Mona']),
            Customer::query()->create(['phone' => '01033334444', 'name' => 'Omar']),
        ]);
        $this->voucher($a, ['code' => 'OMARONLY', 'customer_id' => $omar->id]);
        $this->voucher($a, ['code' => 'EVERYONE']);

        $this->actingAs($cashier)->get($this->dashUrl('/loyalty/vouchers'))->assertForbidden();      // setup needs loyalty.manage
        // only a manager (or anyone given vouchers.give) hands out vouchers; a cashier sees them and redeems them
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q=01011112222'))->assertOk()->assertDontSee('data-give-voucher', false);
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/customers/{$mona->id}/vouchers"), ['type' => 'amount', 'value' => 30, 'days' => 7])
            ->assertForbidden();
        $manager = $this->staff($a, 'manager', 'manager@cafe-a.test');
        $this->actingAs($manager)->get($this->dashUrl('/loyalty?q=01011112222'))->assertOk()->assertSee('data-give-voucher', false);
        $this->actingAs($manager)->post($this->dashUrl("/loyalty/customers/{$mona->id}/vouchers"), ['type' => 'amount', 'value' => 30, 'days' => 7])
            ->assertRedirect()->assertSessionHasNoErrors();
        $given = $this->as($a, fn () => Coupon::query()->where('customer_id', $mona->id)->sole());
        $this->assertSame(1, $given->max_uses);
        $this->assertTrue($given->expires_at->isSameDay(now()->addDays(7)));
        $this->actingAs($cashier)->get($this->dashUrl('/loyalty?q=01011112222'))->assertOk()->assertSee($given->code)->assertDontSee('OMARONLY');

        $this->get($this->subUrl('cafe-a', "card/{$mona->card_token}"))->assertOk()
            ->assertSee('data-member-voucher="'.$given->code.'"', false)->assertSee('?coupon='.$given->code, false)
            ->assertDontSee('OMARONLY')->assertDontSee('EVERYONE');
    }

    public function test_the_pos_takes_a_voucher_code_and_the_receipt_shows_it(): void
    {
        $a = $this->restaurant('cafe-a');
        app(Billing::class)->setAddon($a, 'pos_pack', 1);
        $item = $this->item($a, 'Latte', 100);
        $mona = $this->as($a, fn () => Customer::query()->create(['phone' => '01011112222']));
        $this->voucher($a, ['code' => 'MONA25', 'value' => 25, 'customer_id' => $mona->id]);
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $cart = ['items' => [['item_id' => $item->id, 'qty' => 2]], 'coupon' => 'mona25'];

        $this->actingAs($cashier)->postJson($this->dashUrl('/pos/coupon'), $cart + ['customer_phone' => '01099998888'])->assertStatus(422);
        $this->actingAs($cashier)->postJson($this->dashUrl('/pos/coupon'), $cart + ['customer_phone' => '01011112222'])->assertOk()
            ->assertJson(['code' => 'MONA25', 'discount' => 50]);

        $id = $this->actingAs($cashier)->postJson($this->dashUrl('/pos/orders'), $cart + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'customer_phone' => '01011112222',
        ])->assertOk()->json('id');
        $order = $this->as($a, fn () => Order::query()->find($id));
        $this->assertSame('MONA25', $order->coupon_code);
        $this->assertSame(150.0, $order->total);
        $this->actingAs($cashier)->get($this->dashUrl("/orders/$id/receipt"))->assertOk()->assertSee('MONA25')->assertSee('50');
    }

    public function test_vouchers_need_the_loyalty_card_feature(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($a, 'pos_pack', 1);
        $item = $this->item($a, 'Latte', 100);
        $this->voucher($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers'))->assertForbidden();
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/vouchers/batch'), ['count' => 3, 'type' => 'percent', 'value' => 10])->assertForbidden();
        $this->actingAs($owner)->postJson($this->dashUrl('/pos/coupon'), ['items' => [['item_id' => $item->id, 'qty' => 1]], 'coupon' => 'GIFT20'])->assertForbidden();
        $this->actingAs($owner)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'qty' => 1]], 'coupon' => 'GIFT20',
        ])->assertForbidden();
    }

    public function test_vouchers_stay_inside_their_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $itemA = $this->item($a, 'Latte', 100);
        $theirs = $this->voucher($b, ['code' => 'BONLY']);
        $guestB = $this->as($b, fn () => Customer::query()->create(['phone' => '01011112222']));
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl("/loyalty/vouchers/{$theirs->id}/toggle"))->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl("/loyalty/vouchers/print?ids={$theirs->id}"))->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers/export?batch=x'))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/loyalty/customers/{$guestB->id}/vouchers"), ['type' => 'amount', 'value' => 30, 'days' => 7])->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl('/loyalty/vouchers'))->assertOk()->assertDontSee('BONLY');
        $this->assertSame(__('This coupon code is not valid.'), $this->order($a, [[$itemA, 1]], '01011112222', 'BONLY'));
        $this->assertTrue($theirs->fresh()->is_active);
    }
}
