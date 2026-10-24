<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderConfirmation;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * WhatsApp confirmation of cash-on-delivery delivery orders: asked once, answered by quick-reply buttons (or the
 * signed fallback page), shown as a badge only (the kitchen never waits), and never across restaurants.
 */
class OrderConfirmationTest extends TenantTestCase
{
    private const SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.app_secret' => self::SECRET]);
    }

    /** A restaurant with the confirmation switched on and WhatsApp credit. */
    private function shop(string $slug, array $settings = []): Tenant
    {
        $tenant = $this->restaurant($slug);
        app(Credits::class)->add($tenant, 20, 'adjustment');
        $this->as($tenant, fn () => Settings::put($settings + ['wa_order_confirm' => '1', 'phone' => '0223456789']));

        return $tenant;
    }

    private function place(Tenant $tenant, string $type = 'delivery', string $source = 'online', string $phone = '01012345678', int $lines = 1): Order
    {
        $item = $this->item($tenant, 'Burger', 120);

        return $this->as($tenant, function () use ($item, $type, $source, $phone, $lines) {
            $branch = Branch::query()->first();
            $zone = $type === 'delivery' ? DeliveryZone::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Maadi'], ['fee' => 20, 'is_active' => true]) : null;

            return app(OrderService::class)->place([
                'items' => array_fill(0, $lines, ['item_id' => $item->id, 'qty' => 1]), 'customer_phone' => $phone, 'customer_name' => 'Sohail',
                'source' => $source, 'type' => $type, 'delivery_zone' => $zone,
                'delivery_address' => $zone ? ['zone' => 'Maadi', 'text' => "12 Street 9,\nfloor 3"] : null,
            ], $branch);
        });
    }

    /** @return Collection<int, WhatsAppMessage> */
    private function messages(Tenant $tenant): Collection
    {
        return $this->as($tenant, fn () => WhatsAppMessage::query()->orderBy('id')->get());
    }

    private function fresh(Order $order): Order
    {
        return Order::query()->withoutGlobalScopes()->findOrFail($order->id);
    }

    /** @param  array<string,mixed>  $message  one entry of value.messages */
    private function postMeta(array $message, ?string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'messages' => [$message + ['id' => 'wamid.'.uniqid(), 'timestamp' => '1']],
        ]]]]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], $headers, $raw);
    }

    /** A tap on a template's quick-reply button, as Meta sends it. */
    private function tap(string $payload, string $from = '201012345678'): TestResponse
    {
        return $this->postMeta(['from' => $from, 'type' => 'button', 'button' => ['payload' => $payload, 'text' => 'x']]);
    }

    public function test_only_cash_on_delivery_delivery_orders_from_the_menu_link_ask_the_guest(): void
    {
        $a = $this->shop('cafe-a', ['wa_order_received' => '1']);
        $order = $this->place($a, lines: 5);

        $message = $this->messages($a)->sole(); // the confirmation replaces "we received your order"
        $this->assertSame('order_confirm', $message->template);
        $this->assertSame('sent', $message->status);
        $this->assertSame(1, $message->cost_credits);
        $this->assertSame(['name', 'number', 'restaurant', 'items', 'total', 'address'], array_keys($message->params));
        $this->assertStringContainsString('Burger ×1، Burger ×1، Burger ×1 +2 أصناف', $message->body);
        $this->assertStringContainsString('المبلغ عند الاستلام: 620 جنيه', $message->body);
        $this->assertStringContainsString('Maadi - 12 Street 9, floor 3', $message->body);
        $this->assertSame(['تأكيد الطلب', 'تعديل الطلب', 'إلغاء الطلب'], array_column($message->buttons, 'title'));

        $order = $this->fresh($order);
        $this->assertSame('pending', $order->confirm_status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $order->confirm_token);
        $this->assertSame('oc:confirm:'.$order->confirm_token, $message->buttons[0]['payload']);
        $this->assertSame($message->id, (int) $order->confirm_message_id);
        $this->assertSame(19, app(Credits::class)->balance($a));

        // takeaway, dine-in, call-center and staff orders are not asked
        $this->place($a, 'takeaway', 'online', '01011111111');
        $this->place($a, 'dine_in', 'pos', '01022222222');
        $this->place($a, 'delivery', 'callcenter', '01033333333');
        $this->assertSame(['order_confirm', 'order_received', 'order_received'], $this->messages($a)->pluck('template')->all());

        // a delivery order already paid (wallet) is not asked
        $paid = $this->place($a, 'delivery', 'pos', '01044444444');
        $paid->forceFill(['source' => 'online', 'payment_status' => 'paid'])->save();
        $this->assertFalse($this->as($a, fn () => app(OrderConfirmation::class)->request($this->fresh($paid))));
    }

    public function test_nothing_is_asked_with_the_setting_off_without_credit_or_without_the_plan(): void
    {
        $off = $this->shop('cafe-off', ['wa_order_confirm' => '0']);
        $this->place($off);
        $this->assertCount(0, $this->messages($off));

        $broke = $this->restaurant('cafe-broke');
        $this->as($broke, fn () => Settings::put(['wa_order_confirm' => '1', 'wa_order_received' => '1']));
        $order = $this->place($broke);
        $this->assertNull($this->fresh($order)->confirm_status); // no badge waits for a message that never left
        $this->assertSame(['order_received'], $this->messages($broke)->pluck('template')->all());

        $basic = $this->restaurant('cafe-basic', 'basic');
        app(Credits::class)->add($basic, 5, 'adjustment');
        $this->as($basic, fn () => Settings::put(['wa_order_confirm' => '1']));
        $this->place($basic);
        $this->assertCount(0, $this->messages($basic));
        app(Billing::class)->setAddon($basic, 'whatsapp_pack', 1);
        $this->place($basic, phone: '01055555555');
        $this->assertSame(['order_confirm'], $this->messages($basic)->pluck('template')->all());
    }

    public function test_each_button_updates_the_order_once_and_gets_its_free_reply(): void
    {
        $a = $this->shop('cafe-a');
        $confirm = $this->fresh($this->place($a));
        $edit = $this->fresh($this->place($a, phone: '01011111111'));
        $cancel = $this->fresh($this->place($a, phone: '01022222222'));
        $balance = app(Credits::class)->balance($a);

        $this->tap('oc:confirm:'.$confirm->confirm_token)->assertOk();
        $this->tap('oc:confirm:'.$confirm->confirm_token)->assertOk(); // again: nothing more happens
        // an interactive reply button carries the payload as its id
        $this->postMeta(['from' => '201011111111', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply',
            'button_reply' => ['id' => 'oc:edit:'.$edit->confirm_token, 'title' => 'تعديل الطلب']]])->assertOk();
        $this->tap('oc:cancel:'.$cancel->confirm_token, '201022222222')->assertOk();

        $this->assertSame(['confirmed', 'whatsapp'], [$this->fresh($confirm)->confirm_status, $this->fresh($confirm)->confirm_via]);
        $this->assertSame('edit', $this->fresh($edit)->confirm_status);
        // "cancel" only asks: the order itself is never cancelled by the guest's tap
        $this->assertSame('cancel_requested', $this->fresh($cancel)->confirm_status);
        $this->assertSame('new', $this->fresh($cancel)->status);

        $replies = $this->messages($a)->where('template', 'session_reply')->values();
        $this->assertCount(3, $replies);
        $this->assertSame("تمام! ✅ أكدنا طلبك #{$confirm->number} وهنجهّزه ونبعته في أقرب وقت.", $replies[0]->body);
        $this->assertSame('201012345678', $replies[0]->to);
        $this->assertStringContainsString('هنكلمك حالاً نعدّل الطلب', $replies[1]->body);
        $this->assertStringContainsString('0223456789', $replies[1]->body);
        $this->assertStringContainsString('طلب إلغاء', $replies[2]->body);
        $this->assertSame([0, 0, 0], $replies->pluck('cost_credits')->all());
        $this->assertSame($balance, app(Credits::class)->balance($a)); // replies inside the guest's window are free

        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'order.confirm.confirm')->count()));
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'order.confirm.cancel')->count()));
    }

    public function test_a_tap_cannot_touch_another_number_or_another_restaurants_order(): void
    {
        $a = $this->shop('cafe-a');
        $b = $this->shop('cafe-b');
        $orderA = $this->fresh($this->place($a));
        $orderB = $this->fresh($this->place($b)); // same guest, same number, another restaurant

        $this->tap('oc:confirm:'.$orderA->confirm_token, '201099999999')->assertOk(); // a stranger's phone
        $this->tap('oc:confirm:'.$orderA->id)->assertOk();                              // a guessable id
        $this->tap('oc:confirm:'.str_repeat('a', 32))->assertOk();                       // an unknown token
        $this->tap('confirm')->assertOk();
        $this->assertSame('pending', $this->fresh($orderA)->confirm_status);
        $this->assertSame(0, $this->messages($a)->where('template', 'session_reply')->count());

        $this->tap('oc:confirm:'.$orderB->confirm_token)->assertOk();
        $this->assertSame('pending', $this->fresh($orderA)->confirm_status);
        $this->assertSame('confirmed', $this->fresh($orderB)->confirm_status);
        $this->assertSame(0, $this->messages($a)->where('template', 'session_reply')->count());
        $this->assertSame(1, $this->messages($b)->where('template', 'session_reply')->count());
        $this->assertSame(0, $this->as($a, fn () => AuditLog::query()->where('action', 'like', 'order.confirm.%')->count()));
    }

    public function test_the_webhook_still_needs_metas_signature(): void
    {
        $a = $this->shop('cafe-a');
        $order = $this->fresh($this->place($a));
        $message = ['from' => '201012345678', 'type' => 'button', 'button' => ['payload' => 'oc:confirm:'.$order->confirm_token]];

        $this->postMeta($message, null)->assertForbidden();
        $this->postMeta($message, 'other-secret')->assertForbidden();
        $this->assertSame('pending', $this->fresh($order)->confirm_status);
    }

    public function test_a_tap_on_a_finished_order_is_ignored_with_a_polite_reply(): void
    {
        $a = $this->shop('cafe-a');
        $order = $this->fresh($this->place($a));
        $this->as($a, fn () => app(OrderService::class)->transition(Order::query()->find($order->id), 'cancelled', null, 'test'));

        $this->tap('oc:confirm:'.$order->confirm_token)->assertOk();
        $this->assertSame('pending', $this->fresh($order)->confirm_status);
        $reply = $this->messages($a)->where('template', 'session_reply')->sole();
        $this->assertStringContainsString('مبقاش محتاج تأكيد', $reply->body);
    }

    public function test_no_answer_shows_as_no_reply_after_the_minutes_and_staff_confirm_by_phone(): void
    {
        $a = $this->shop('cafe-a', ['wa_order_confirm_minutes' => '15']);
        $order = $this->fresh($this->place($a));
        $owner = $this->owner($a);

        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk();
        $this->assertSame('pending', $feed->json('orders.0.confirm.state'));
        $this->assertSame('مستني تأكيد العميل', $feed->json('orders.0.confirm.label'));
        $this->assertSame(1, $feed->json('counts.confirm'));

        $this->travel(16)->minutes();
        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk();
        $this->assertSame('no_reply', $feed->json('orders.0.confirm.state'));
        $this->assertSame('مأكّدش', $feed->json('orders.0.confirm.label'));

        $this->actingAs($owner)->postJson($this->dashUrl("/orders/{$order->id}/confirm-phone"))->assertOk()
            ->assertJsonPath('order.confirm.label', 'اتأكد بالتليفون')->assertJsonPath('order.confirm.open', false);
        $this->assertSame(['confirmed', 'phone', $owner->id], [$this->fresh($order)->confirm_status, $this->fresh($order)->confirm_via, (int) $this->fresh($order)->confirm_by]);
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/{$order->id}/confirm-phone"))->assertStatus(422);
        $this->assertSame(0, $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->json('counts.confirm'));

        // the board page offers the filter; the kitchen ticket carries the small badge
        app(Billing::class)->setAddon($a, 'kitchen_pack', 1);
        $this->actingAs($owner)->get($this->dashUrl('/orders'))->assertOk()->assertSee('تأكيد العميل');
        $this->actingAs($owner)->getJson($this->dashUrl('/kitchen/feed'))->assertOk()->assertJsonPath('tickets.0.confirm.state', 'phone');
    }

    public function test_the_guests_answer_reaches_the_bell(): void
    {
        $a = $this->shop('cafe-a');
        $order = $this->fresh($this->place($a));
        $this->tap('oc:cancel:'.$order->confirm_token)->assertOk();

        $items = collect($this->actingAs($this->owner($a))->getJson($this->dashUrl('/notifications'))->assertOk()->json('items'));
        $this->assertSame('طلب رقم '.$order->number.' · العميل طالب إلغاء', $items->firstWhere('kind', 'confirm')['title']);
    }

    public function test_a_provider_without_buttons_sends_the_signed_page_which_works_with_a_valid_signature_only(): void
    {
        config(['whatsapp.driver' => 'webhook', 'whatsapp.webhook.url' => 'https://wa.example.test/send', 'whatsapp.webhook.secret' => 's3cret']);
        Http::fake(['wa.example.test/*' => Http::response(['id' => 'p-1'])]);
        $a = $this->shop('cafe-a');
        $b = $this->shop('cafe-b');
        $order = $this->fresh($this->place($a));
        $url = $this->as($a, fn () => $order->confirmUrl());

        $this->assertStringContainsString("/cafe-a/oc/{$order->confirm_token}/", $url);
        Http::assertSent(fn ($r) => $r['template'] === 'order_confirm' && str_contains($r['text'], 'أكّد أو عدّل أو الغِ من هنا: '.$url));
        $this->assertStringContainsString($url, $this->messages($a)->sole()->body);

        $this->get($this->pathUrl('cafe-a', "oc/{$order->confirm_token}/".str_repeat('0', 32)))->assertNotFound();
        $this->get(str_replace('/cafe-a/', '/cafe-b/', $url))->assertNotFound(); // another restaurant's address
        $this->post($url, ['action' => 'confirm'])->assertRedirect(); // (CSRF is off in tests)
        $this->post(str_replace('/cafe-a/', '/cafe-b/', $url), ['action' => 'cancel'])->assertNotFound();
        $this->assertSame(['confirmed', 'link'], [$this->fresh($order)->confirm_status, $this->fresh($order)->confirm_via]);

        $this->get($url)->assertOk()->assertSee('#'.$order->number)->assertSee('ردّك وصلنا:')->assertDontSee('name="action"', false);
        $this->post($url, ['action' => 'cancel'])->assertRedirect();
        $this->assertSame('confirmed', $this->fresh($order)->confirm_status); // answered once
    }

    public function test_the_confirmation_page_shows_the_three_choices_while_waiting(): void
    {
        $a = $this->shop('cafe-a');
        $order = $this->fresh($this->place($a));
        $url = $this->as($a, fn () => $order->confirmUrl());

        $this->get($url)->assertOk()->assertSee('تأكيد الطلب')->assertSee('تعديل الطلب')->assertSee('إلغاء الطلب')->assertSee('140');
    }

    public function test_meta_gets_the_template_with_quick_reply_buttons_and_the_reply_as_free_text(): void
    {
        config(['whatsapp.driver' => 'meta', 'whatsapp.meta.phone_id' => '123', 'whatsapp.meta.token' => 'secret-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $a = $this->shop('cafe-a');
        $order = $this->fresh($this->place($a));

        Http::assertSent(function ($r) use ($order) {
            $components = $r['template']['components'] ?? [];
            $body = collect($components[0]['parameters'] ?? [])->pluck('text');

            return $r['template']['name'] === 'egmenu_order_confirm' && $body->count() === 6
                && $body->every(fn ($text) => ! str_contains($text, "\n"))
                && collect(array_slice($components, 1))->map(fn ($c) => [$c['sub_type'], $c['index'], $c['parameters'][0]['payload']])->all() === [
                    ['quick_reply', '0', 'oc:confirm:'.$order->confirm_token],
                    ['quick_reply', '1', 'oc:edit:'.$order->confirm_token],
                    ['quick_reply', '2', 'oc:cancel:'.$order->confirm_token],
                ];
        });

        $this->tap('oc:confirm:'.$order->confirm_token)->assertOk();
        Http::assertSent(fn ($r) => ($r['type'] ?? '') === 'text' && str_contains($r['text']['body'], 'أكدنا طلبك'));
    }

    public function test_a_message_that_never_left_removes_the_badge(): void
    {
        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway
        {
            public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
            {
                throw new WhatsAppException('Recipient not on WhatsApp');
            }
        });
        $a = $this->shop('cafe-a');
        $order = $this->place($a);

        $this->assertSame('failed', $this->messages($a)->sole()->status);
        $this->assertNull($this->fresh($order)->confirm_status);
        $this->assertSame(20, app(Credits::class)->balance($a));
    }
}
