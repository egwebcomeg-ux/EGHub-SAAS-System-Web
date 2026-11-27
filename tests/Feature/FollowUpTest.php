<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** «متابعة العملاء»: every order of the day, from every source, opened on WhatsApp Web by the staff. */
class FollowUpTest extends TenantTestCase
{
    private function place(Tenant $tenant, MenuItem $item, string $source, string $phone = '01012345678', string $type = 'takeaway'): Order
    {
        return $this->as($tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => $phone, 'customer_name' => 'Mona', 'source' => $source, 'type' => $type,
        ], Branch::query()->first()));
    }

    public function test_the_day_orders_of_every_source_open_whatsapp_web_with_a_message_for_their_status(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->as($a, fn () => Settings::put(['brand_name' => 'Kahwa']));
        $latte = $this->item($a, 'Latte', 65);
        $online = $this->place($a, $latte, 'online');
        $cashier = $this->place($a, $latte, 'pos', '01122223333');
        $landline = $this->place($a, $latte, 'callcenter', '0223456789');
        $old = $this->place($a, $latte, 'online', '01299998888');
        $this->as($a, function () use ($cashier, $old) {
            Order::query()->whereKey($cashier->id)->update(['status' => 'served']);
            Order::query()->whereKey($old->id)->update(['created_at' => now()->subDays(2)]);
        });
        $waiter = $this->staff($a, 'waiter', 'waiter@a.test');

        $page = $this->actingAs($waiter)->get($this->dashUrl('/follow-up'))->assertOk()
            ->assertSee('data-followup-row="'.$online->id.'"', false)->assertSee('data-followup-row="'.$cashier->id.'"', false)
            ->assertSee('data-followup-row="'.$landline->id.'"', false)->assertDontSee('data-followup-row="'.$old->id.'"', false)
            ->assertSee('مش رقم موبايل');
        $html = $page->getContent();

        $preparing = 'https://web.whatsapp.com/send?phone=201012345678&amp;text='
            .e(rawurlencode('أهلاً Mona، معاك Kahwa. طلبك رقم '.$online->number.' وصلنا وبيتجهز دلوقتي. تقدر تتابعه من هنا: '.$a->url('o/'.$online->tracking_token)));
        $this->assertStringContainsString($preparing, $html);
        $this->assertStringContainsString('phone=201122223333&amp;text='.e(rawurlencode('أهلاً Mona، شكراً إنك طلبت من Kahwa.')), $html);
        $this->assertStringContainsString('https://wa.me/201012345678?text=', $html);

        // the cancelled filter shows none of them
        $this->actingAs($waiter)->get($this->dashUrl('/follow-up?status=cancelled'))->assertOk()->assertDontSee('data-followup-row="', false);
    }

    public function test_the_first_agent_who_opens_the_chat_keeps_the_mark(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $order = $this->place($a, $this->item($a, 'Latte'), 'online');
        $first = $this->staff($a, 'waiter', 'first@a.test');
        $second = $this->staff($a, 'manager', 'second@a.test');

        $this->actingAs($first)->postJson($this->dashUrl('/follow-up/'.$order->id))->assertOk()->assertJsonPath('by', 'Waiter');
        $this->actingAs($second)->postJson($this->dashUrl('/follow-up/'.$order->id))->assertOk()->assertJsonPath('by', 'Waiter');
        $this->assertSame($first->id, $this->as($a, fn () => $order->fresh()->followed_up_by));

        $this->actingAs($second)->get($this->dashUrl('/follow-up'))->assertOk()->assertSee('اتتابع');
        $this->actingAs($second)->get($this->dashUrl('/follow-up?pending=1'))->assertOk()->assertDontSee('data-followup-row="'.$order->id.'"', false);
    }

    public function test_another_restaurant_or_a_kitchen_account_cannot_reach_it(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $order = $this->place($a, $this->item($a, 'Latte'), 'online');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->place($b, $this->item($b, 'Tea'), 'online', '01155556666');

        $this->actingAs($this->owner($b))->postJson($this->dashUrl('/follow-up/'.$order->id))->assertNotFound();
        $this->actingAs($this->owner($b))->get($this->dashUrl('/follow-up'))->assertOk()
            ->assertDontSee('data-followup-row="'.$order->id.'"', false)->assertSee('201155556666');
        $this->assertNull($this->as($a, fn () => $order->fresh()->followed_up_at));

        $kitchen = $this->staff($a, 'kitchen', 'kitchen@a.test');
        $this->actingAs($kitchen)->get($this->dashUrl('/follow-up'))->assertForbidden();
        $this->actingAs($kitchen)->postJson($this->dashUrl('/follow-up/'.$order->id))->assertForbidden();
    }

    public function test_the_call_center_records_a_whatsapp_chat_as_a_whatsapp_order_handled_like_a_call(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($a, 'callcenter_pack', 1);
        $latte = $this->item($a, 'Latte', 60);
        $agent = $this->staff($a, 'callcenter', 'agent@a.test');

        $this->actingAs($agent)->get($this->dashUrl('/call-center'))->assertOk()->assertSee('data-cc-channel', false);
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'channel' => 'whatsapp',
            'items' => [['item_id' => $latte->id, 'qty' => 1]], 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ])->assertOk();
        $this->actingAs($agent)->postJson($this->dashUrl('/call-center/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'channel' => 'sms',
            'items' => [['item_id' => $latte->id, 'qty' => 1]], 'customer_phone' => '01012345678',
        ])->assertUnprocessable();

        $order = $this->as($a, fn () => Order::query()->sole());
        $this->assertSame('whatsapp', $order->source);
        $this->assertSame($agent->id, $order->created_by);
        $this->assertSame('callcenter', $this->as($a, fn () => Customer::query()->sole()->source));

        $this->actingAs($agent)->getJson($this->dashUrl('/call-center/customer?phone=01012345678'))->assertOk()
            ->assertJsonPath('whatsapp', 'https://web.whatsapp.com/send?phone=201012345678');
    }
}
