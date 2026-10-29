<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Order;
use App\Notifications\DailyDigestReport;
use App\Services\Settings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** The manager's morning digest: yesterday's sales, cancellations with who approved them, cash, what is left open. */
class DailyDigestTest extends TenantTestCase
{
    public function test_owners_and_managers_get_yesterday_once_with_what_needs_their_eye(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a', 'pro');
        app(Billing::class)->setAddon($a, 'pos_pack', 1);
        $this->as($a, fn () => Settings::put(['order_flow' => 'kitchen']));
        $latte = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);
        $manager = $this->staff($a, 'manager', 'manager@cafe-a.test');
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');

        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $sale = fn (array $extra = []) => $this->actingAs($cashier)->postJson($this->dashUrl('/pos/orders'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $latte->id, 'qty' => 2]],
        ])->assertOk()->json('id');
        $sale(['pay' => 'cash']);
        $wrong = $sale();
        $sale();
        $this->actingAs($manager)->postJson($this->dashUrl("/orders/$wrong/status"), ['status' => 'cancelled', 'reason' => 'Guest left'])->assertOk();
        // 120 in the drawer on top of the 100: counted 200, 20 short
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 200])->assertSessionHasNoErrors();

        $this->travel(1)->days();
        $this->artisan('egmenu:daily-digest')->assertSuccessful();

        Notification::assertSentTo([$owner, $manager], DailyDigestReport::class, function (DailyDigestReport $n) {
            $d = $n->digest;
            $this->assertSame([2, 120.0, ['cash' => 120.0]], [$d['orders'], $d['revenue'], $d['methods']]);
            $this->assertSame([['order' => '#'.Order::query()->withoutGlobalScopes()->orderBy('id')->skip(1)->value('number'), 'reason' => 'Guest left', 'approved_by' => 'Manager', 'by' => 'Manager']], $d['cancelled']);
            $this->assertSame([-20.0], array_column($d['shifts'], 'difference'));
            $this->assertSame(2, $d['open_orders']); // the paid sale and the unpaid one, both still in the kitchen

            return true;
        });
        Notification::assertNotSentTo($cashier, DailyDigestReport::class);

        // a rerun the same morning sends nothing, and neither does a quiet day
        $this->artisan('egmenu:daily-digest')->assertSuccessful();
        Notification::assertSentToTimes($owner, DailyDigestReport::class, 1);
        $this->travel(1)->days();
        $this->artisan('egmenu:daily-digest')->assertSuccessful();
        Notification::assertSentToTimes($owner, DailyDigestReport::class, 1);
    }

    public function test_it_can_be_turned_off(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/settings?tab=messages'))->assertOk()->assertSee('data-daily-digest', false);
        $this->as($a, fn () => Settings::put(['daily_digest' => '0']));
        $this->item($a, 'Latte', 60);
        $this->as($a, fn () => Order::query()->create([
            'branch_id' => Branch::query()->value('id'), 'number' => 1, 'client_uuid' => (string) Str::uuid(), 'tracking_token' => random_token(12),
            'type' => 'takeaway', 'source' => 'online', 'status' => 'new', 'subtotal' => 60, 'total' => 60,
        ]));

        $this->travel(1)->days();
        $this->artisan('egmenu:daily-digest')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
