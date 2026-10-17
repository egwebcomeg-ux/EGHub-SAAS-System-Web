<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Tests\TenantTestCase;

/** New table / online / kiosk orders can also reach the branch's own WhatsApp number (setting wa_order_to_staff). */
class StaffOrderWhatsAppTest extends TenantTestCase
{
    private function placeOnline(Tenant $tenant, $item, string $source = 'online')
    {
        return $this->as($tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 2]], 'source' => $source, 'type' => 'takeaway',
            'customer_phone' => '01012345678', 'customer_name' => 'Mona', 'notes' => 'بدون سكر',
        ], Branch::query()->firstOrFail(), null, null));
    }

    public function test_the_alert_goes_to_the_branch_number_when_switched_on(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $item = $this->item($tenant, 'Latte', 60);
        $this->as($tenant, function () {
            Settings::put(['wa_order_to_staff' => '1', 'whatsapp' => '01000000001']);
            Branch::query()->firstOrFail()->update(['whatsapp' => '01155512345']);
        });

        $order = $this->placeOnline($tenant, $item);
        $this->as($tenant, function () use ($order) {
            $message = WhatsAppMessage::query()->where('template', 'order_staff')->firstOrFail();
            $this->assertSame(whatsapp_number('01155512345'), $message->to);
            $this->assertStringContainsString("#{$order->number}", $message->body);
            $this->assertStringContainsString('2× Latte', $message->body);
            $this->assertStringContainsString('120', $message->body);
            $this->assertStringContainsString('بدون سكر', $message->body);
            $this->assertStringContainsString(platform_url('app', 'orders'), $message->body);
        });
    }

    public function test_it_falls_back_to_the_restaurant_number_and_skips_counter_orders(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $item = $this->item($tenant, 'Latte', 60);
        $this->as($tenant, fn () => Settings::put(['wa_order_to_staff' => '1', 'whatsapp' => '01000000001']));

        $this->placeOnline($tenant, $item);
        $this->assertSame(whatsapp_number('01000000001'), $this->as($tenant, fn () => WhatsAppMessage::query()->where('template', 'order_staff')->value('to')));

        // the cashier typed this one in: nobody needs a WhatsApp about it
        $owner = $this->owner($tenant);
        $this->as($tenant, fn () => app(OrderService::class)->place(['items' => [['item_id' => $item->id, 'qty' => 1]], 'source' => 'pos', 'type' => 'takeaway'],
            Branch::query()->firstOrFail(), null, $owner));
        $this->assertSame(1, $this->as($tenant, fn () => WhatsAppMessage::query()->where('template', 'order_staff')->count()));
    }

    public function test_nothing_is_sent_when_off_or_without_a_number_and_the_owner_can_switch_it_on(): void
    {
        $tenant = $this->restaurant('cafe-a', 'pro');
        $item = $this->item($tenant, 'Latte', 60);
        $this->placeOnline($tenant, $item);
        $this->as($tenant, fn () => Settings::put(['wa_order_to_staff' => '1'])); // on, but no number anywhere
        $this->placeOnline($tenant, $item);
        $this->assertSame(0, $this->as($tenant, fn () => WhatsAppMessage::query()->where('template', 'order_staff')->count()));

        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="wa_order_to_staff"', false);
        $form = ['brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522', 'color_secondary' => '#0A2540',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'];
        $this->actingAs($owner)->post($this->dashUrl('/settings'), $form + ['wa_order_to_staff' => 1, 'whatsapp' => '01000000001'])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($tenant, fn () => Settings::get('wa_order_to_staff')));

        // the Basic plan has ordering but no WhatsApp notifications at all
        $free = $this->restaurant('free-b', 'basic');
        $freeItem = $this->item($free, 'Tea', 20);
        $this->as($free, fn () => Settings::put(['wa_order_to_staff' => '1', 'whatsapp' => '01000000002']));
        $this->placeOnline($free, $freeItem, 'qr');
        $this->assertSame(0, $this->as($free, fn () => WhatsAppMessage::query()->count()));
    }
}
