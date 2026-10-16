<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\AutoCancel;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

/** Online orders nobody accepted in time are cancelled once by the scheduled command; everything else is left alone. */
class AutoCancelTest extends TenantTestCase
{
    private function place(Tenant $tenant, array $data, bool $atTable = false): Order
    {
        return $this->as($tenant, function () use ($data, $atTable) {
            $branch = Branch::query()->firstOrFail();
            $item = MenuItem::query()->firstOrFail();
            $table = $atTable ? DiningTable::query()->where('branch_id', $branch->id)->firstOrFail() : null;

            return app(OrderService::class)->place($data + ['items' => [['item_id' => $item->id, 'qty' => 1]]], $branch, $table);
        });
    }

    public function test_only_old_unaccepted_online_orders_are_cancelled_and_only_once(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);
        $this->as($a, fn () => Settings::put(['online_auto_cancel_minutes' => '15']));

        $this->travelTo(now()->subMinutes(30));
        $late = $this->place($a, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);
        $delivery = $this->place($a, ['source' => 'qr', 'type' => 'delivery', 'customer_phone' => '01012345678']);
        $table = $this->place($a, ['source' => 'qr', 'type' => 'dine_in'], true);
        $pos = $this->place($a, ['source' => 'pos', 'type' => 'takeaway']);
        $accepted = $this->place($a, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);
        $this->as($a, fn () => app(OrderService::class)->transition($accepted, 'preparing'));
        $scheduled = $this->place($a, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);
        $this->as($a, fn () => $scheduled->update(['scheduled_for' => now()->addHours(5)]));
        $this->travelBack();
        $fresh = $this->place($a, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);

        Artisan::call('egmenu:auto-cancel');
        $this->assertStringContainsString('auto-cancelled orders: 2', Artisan::output());

        $status = fn (Order $o) => $this->as($a, fn () => Order::query()->findOrFail($o->id));
        $this->assertSame('cancelled', $status($late)->status);
        $this->assertSame(Order::REASON_NOT_ACCEPTED, $status($late)->cancel_reason);
        $this->assertNotNull($status($late)->cancelled_at);
        $this->assertSame('cancelled', $status($delivery)->status);
        $this->assertSame('new', $status($table)->status);     // dine-in at a table
        $this->assertSame('new', $status($pos)->status);       // staff order
        $this->assertSame('preparing', $status($accepted)->status);
        $this->assertSame('new', $status($scheduled)->status); // for later: the restaurant decides
        $this->assertSame('new', $status($fresh)->status);     // not old enough yet

        // the second run finds nothing more (and the history has exactly one cancellation per order)
        Artisan::call('egmenu:auto-cancel');
        $this->assertStringContainsString('auto-cancelled orders: 0', Artisan::output());
        $this->assertSame(1, $this->as($a, fn () => $late->history()->where('to_status', 'cancelled')->count()));

        // the guest reads why on the tracking page
        $this->get($this->subUrl('cafe-a', 'o/'.$late->tracking_token))->assertOk()
            ->assertSee('data-cancel-reason', false)->assertSee(__('Sorry, the restaurant could not accept your order in time, so it was cancelled.'));
    }

    public function test_off_by_default_and_isolated_per_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');
        $this->item($a);
        $this->item($b);
        $this->as($b, fn () => Settings::put(['online_auto_cancel_minutes' => '10']));

        $this->travelTo(now()->subHour());
        $orderA = $this->place($a, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);
        $orderB = $this->place($b, ['source' => 'online', 'type' => 'takeaway', 'customer_phone' => '01012345678']);
        $this->travelBack();

        $this->assertSame(0, $this->as($a, fn () => app(AutoCancel::class)->run())); // setting is 0 = off
        $this->assertSame(1, $this->as($b, fn () => app(AutoCancel::class)->run()));
        $this->assertSame('new', $this->as($a, fn () => Order::query()->findOrFail($orderA->id)->status));
        $this->assertSame('cancelled', $this->as($b, fn () => Order::query()->findOrFail($orderB->id)->status));
    }

    public function test_the_setting_is_saved_from_the_online_ordering_section(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), [
            'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#E8642C', 'color_secondary' => '#1E2D4F',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'online_auto_cancel_minutes' => 20,
        ])->assertRedirect();
        $this->assertSame('20', $this->as($a, fn () => Settings::get('online_auto_cancel_minutes')));

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), [
            'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#E8642C', 'color_secondary' => '#1E2D4F',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'online_auto_cancel_minutes' => 999,
        ])->assertSessionHasErrors('online_auto_cancel_minutes');
    }
}
