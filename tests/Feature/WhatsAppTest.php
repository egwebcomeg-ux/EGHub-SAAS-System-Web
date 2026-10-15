<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Notify\ReservationNotifier;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\WhatsApp\WhatsApp;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/** WhatsApp channel: credit wallet, transactional messages to guests, credit packs and the providers. */
class WhatsAppTest extends TenantTestCase
{
    private function credits(): Credits
    {
        return app(Credits::class);
    }

    /** @param array<string,string> $toggles */
    private function settings(Tenant $t, array $toggles): void
    {
        $this->as($t, fn () => Settings::put($toggles));
    }

    private function order(Tenant $t, string $source = 'online', string $phone = '01012345678', string $type = 'takeaway'): Order
    {
        $item = $this->item($t, 'Latte', 50);

        return $this->as($t, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => $phone, 'customer_name' => 'Mona',
            'source' => $source, 'type' => $type,
        ], Branch::query()->first()));
    }

    /** @return Collection<int, WhatsAppMessage> */
    private function messages(Tenant $t)
    {
        return $this->as($t, fn () => WhatsAppMessage::query()->orderBy('id')->get());
    }

    public function test_a_message_takes_one_credit_and_is_logged(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 5, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1', 'wa_order_ready' => '1']);

        $order = $this->order($a);
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'preparing'));
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'ready'));

        $messages = $this->messages($a);
        $this->assertSame(['order_received', 'order_ready'], $messages->pluck('template')->all());
        $this->assertSame(['sent', 'sent'], $messages->pluck('status')->all());
        $this->assertSame('201012345678', $messages[0]->to);
        $this->assertSame(1, $messages[0]->cost_credits);
        $this->assertStringContainsString('#'.$order->number, str_replace('رقم ', '#', $messages[1]->body));
        $this->assertStringContainsString('/cafe-a/o/'.$order->tracking_token, $messages[1]->body);
        $this->assertStringStartsWith('log-', $messages[0]->provider_id);
        $this->assertSame(3, $this->credits()->balance($a));
        $this->assertSame(2, CreditTransaction::query()->where('tenant_id', 'cafe-a')->where('reason', 'message')->count());
    }

    public function test_without_credits_the_message_is_skipped_and_the_owner_is_warned(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->settings($a, ['wa_order_received' => '1']);
        $this->order($a);

        $message = $this->messages($a)->sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame(WhatsAppMessage::NO_CREDITS, $message->error);
        $this->assertSame(0, $message->cost_credits);
        $this->assertSame(0, $this->credits()->balance($a));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()->assertSee('رصيد الواتساب خلص');
        $this->credits()->add($a, 10, 'adjustment');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()->assertDontSee('رصيد الواتساب خلص');
    }

    public function test_toggles_sources_and_the_plan_decide_what_is_sent(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 50, 'adjustment');

        // toggles off by default: nothing
        $order = $this->order($a);
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'preparing'));
        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'ready'));
        $this->assertCount(0, $this->messages($a));

        // "received" is for orders the guest placed remotely, not the cashier's own POS orders
        $this->settings($a, ['wa_order_received' => '1', 'wa_order_on_way' => '1']);
        $this->order($a, 'pos');
        $this->assertCount(0, $this->messages($a));
        $this->order($a, 'callcenter', '01099999999');
        $this->assertSame(['order_received'], $this->messages($a)->pluck('template')->all());

        // a plan without the feature sends nothing, whatever the toggles
        $b = $this->restaurant('cafe-b', 'basic');
        $this->credits()->add($b, 50, 'adjustment');
        $this->settings($b, ['wa_order_received' => '1']);
        $this->order($b);
        $this->assertCount(0, $this->messages($b));

        // ...until the WhatsApp add-on is bought
        app(Billing::class)->setAddon($b, 'whatsapp_pack', 1);
        $this->order($b, 'online', '01011112222');
        $this->assertSame(['order_received'], $this->messages($b)->pluck('template')->all());
    }

    public function test_messages_follow_the_restaurant_language(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 5, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1', 'default_locale' => 'en', 'wa_sender_name' => 'Cafe A Team']);
        $this->order($a);

        $message = $this->messages($a)->sole();
        $this->assertSame('en', $message->locale);
        $this->assertStringContainsString('Cafe A Team received your order', $message->body);
        $this->assertSame(['name', 'restaurant', 'number', 'link'], array_keys($message->params));
    }

    public function test_credit_balances_are_kept_per_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->credits()->add($a, 3, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1']);
        $this->settings($b, ['wa_order_received' => '1']);

        $this->order($b);
        $this->assertSame('failed', $this->messages($b)->sole()->status);   // A's credits are not B's
        $this->assertSame(3, $this->credits()->balance($a));

        $this->order($a);
        $this->assertSame(2, $this->credits()->balance($a));
        $this->assertSame(0, $this->credits()->balance($b));
        $this->assertCount(1, $this->messages($a));
        $this->assertCount(1, $this->messages($b));

        // the billing page of each restaurant shows only its own balance
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk()->assertViewHas('creditBalance', 2);
    }

    public function test_a_paid_credit_pack_fills_the_wallet_without_touching_the_subscription(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/billing/addons/wa_500'))->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', 'cafe-a')->sole();
        $this->assertSame(149.0, $invoice->total);
        $this->assertSame(['addon' => 'wa_500', 'quantity' => 1], $invoice->meta);

        $this->assertTrue(app(Billing::class)->markPaid($invoice, 'manual', null, $invoice->total, 'admin:'.$invoice->id));
        $this->assertSame(500, $this->credits()->balance($a));
        $this->assertSame([], $a->subscription()->first()->addons()->pluck('key')->all());
        $this->assertSame($invoice->number, CreditTransaction::query()->where('reason', 'purchase')->value('reference'));

        // a second delivery of the same payment adds nothing
        $this->assertFalse(app(Billing::class)->markPaid($invoice->fresh(), 'manual', null, $invoice->total, 'admin:'.$invoice->id));
        $this->assertSame(500, $this->credits()->balance($a));
        $this->assertSame(2000, AddonPrice::query()->where('key', 'wa_2000')->value('credits'));
    }

    public function test_egweb_adjusts_credits_from_the_restaurant_page(): void
    {
        $a = $this->restaurant('cafe-a');
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/credits'), ['delta' => 120, 'note' => 'gift'])->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/credits'), ['delta' => -500])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->credits()->balance($a));   // never below zero
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/credits'), ['delta' => 40]);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertSee('40');
        $this->assertSame(40, $this->credits()->balance($a));

    }

    public function test_only_egweb_adjusts_credits(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->post($this->adminUrl('/tenants/cafe-a/credits'), ['delta' => 1000]);
        $this->assertSame(0, $this->credits()->balance($a));
    }

    public function test_a_provider_failure_gives_the_credit_back(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 2, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1']);
        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway
        {
            public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
            {
                throw new WhatsAppException('Recipient not on WhatsApp');
            }
        });

        $this->order($a);
        $message = $this->messages($a)->sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Recipient not on WhatsApp', $message->error);
        $this->assertSame(2, $this->credits()->balance($a));
    }

    public function test_meta_driver_sends_the_approved_template_without_leaking_the_token(): void
    {
        config(['whatsapp.driver' => 'meta', 'whatsapp.meta.phone_id' => '123', 'whatsapp.meta.token' => 'secret-token']);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.1']]])
            ->push(['error' => ['message' => 'Invalid token secret-token']], 401)]);
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 1, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1']);

        $this->order($a);
        $this->assertSame('wamid.1', $this->messages($a)->sole()->provider_id);
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/123/messages'
            && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r['template']['name'] === 'egmenu_order_received' && $r['template']['language']['code'] === 'ar'
            && count($r['template']['components'][0]['parameters']) === 4 && $r['to'] === '201012345678');

        $this->credits()->add($a, 1, 'adjustment');
        $this->order($a, 'online', '01022223333');
        $failed = $this->messages($a)->last();
        $this->assertSame('failed', $failed->status);
        $this->assertStringNotContainsString('secret-token', $failed->error);
    }

    public function test_webhook_driver_posts_with_the_shared_secret(): void
    {
        config(['whatsapp.driver' => 'webhook', 'whatsapp.webhook.url' => 'https://wa.example.test/send', 'whatsapp.webhook.secret' => 's3cret']);
        Http::fake(['wa.example.test/*' => Http::response(['id' => 'p-77'])]);
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 1, 'adjustment');
        $this->settings($a, ['wa_order_received' => '1']);

        $this->order($a);
        $this->assertSame('p-77', $this->messages($a)->sole()->provider_id);
        Http::assertSent(fn ($r) => $r->hasHeader('X-EgMenu-Secret', 's3cret') && $r['template'] === 'order_received' && str_contains($r['text'], 'Mona'));
    }

    public function test_loyalty_rewards_and_reservations_send_their_messages(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 5, 'adjustment');
        $this->settings($a, ['wa_loyalty_reward' => '1', 'wa_reservation_confirmed' => '1']);
        $card = $this->as($a, function () {
            LoyaltyProgram::query()->create(['type' => 'visits', 'title' => 'Card', 'stamps_required' => 2, 'reward_label' => 'Free latte']);

            return app(LoyaltyService::class)->enroll('01033334444', 'Sara');
        });
        $this->as($a, fn () => app(LoyaltyService::class)->stamp(LoyaltyCard::query()->find($card->id), 2, $this->owner($a)));

        $this->as($a, function () {
            $reservation = Reservation::query()->create([
                'branch_id' => Branch::query()->first()->id, 'name' => 'Omar', 'phone' => '01055556666', 'party_size' => 4,
                'starts_at' => '2026-10-20 20:00', 'ends_at' => '2026-10-20 21:30', 'status' => 'confirmed', 'source' => 'staff', 'token' => random_token(12),
            ]);
            app(ReservationNotifier::class)->created($reservation);
        });

        $messages = $this->messages($a);
        $this->assertSame(['loyalty_reward', 'reservation_confirmed'], $messages->pluck('template')->all());
        $this->assertStringContainsString('Free latte', $messages[0]->body);
        $this->assertStringContainsString('/cafe-a/card/', $messages[0]->body);
        $this->assertStringContainsString('4', $messages[1]->body);
        $this->assertSame(3, $this->credits()->balance($a));
    }

    public function test_settings_page_saves_the_whatsapp_section(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('wa_sender_name');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), [
            'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#E8642C', 'color_secondary' => '#1E2D4F',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'wa_order_ready' => '1', 'wa_sender_name' => 'كافيه أ',
        ])->assertSessionHasNoErrors();
        $this->as($a, function () {
            $this->assertSame('1', Settings::get('wa_order_ready'));
            $this->assertSame('0', Settings::get('wa_order_received'));
            $this->assertSame('كافيه أ', Settings::get('wa_sender_name'));
        });
    }

    public function test_a_message_delivered_twice_is_sent_and_charged_once(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->credits()->add($a, 5, 'adjustment');
        $this->as($a, function () {
            $message = app(WhatsApp::class)->record('01012345678', 'campaign', ['text' => 'Hi']);
            $stale = WhatsAppMessage::query()->find($message->id);
            app(WhatsApp::class)->deliver($message);
            $this->assertSame('sent', app(WhatsApp::class)->deliver($stale)->status);
        });
        $this->assertSame(4, $this->credits()->balance($a));
        $this->assertSame(1, CreditTransaction::query()->where('tenant_id', 'cafe-a')->where('reason', 'message')->count());
    }
}
