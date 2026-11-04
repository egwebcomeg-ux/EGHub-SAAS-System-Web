<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryTrackPoint;
use App\Models\GuestSignIn;
use App\Models\Order;
use App\Models\PlatformAuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/** The rows nobody reads any more are dropped by the nightly `model:prune`; the recent ones stay. */
class PruningTest extends TenantTestCase
{
    public function test_old_trails_and_spent_sign_in_steps_are_pruned_and_recent_ones_kept(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->as($a, function () use ($owner) {
            $order = Order::query()->create(['branch_id' => Branch::query()->value('id'), 'number' => 1, 'type' => 'delivery', 'status' => 'served', 'subtotal' => 10, 'total' => 10]);
            foreach ([31, 1] as $daysAgo) {
                DeliveryTrackPoint::query()->create(['order_id' => $order->id, 'user_id' => $owner->id, 'lat' => 30.0, 'lng' => 31.0, 'recorded_at' => now()->subDays($daysAgo)]);
                AuditLog::query()->create(['user_id' => $owner->id, 'action' => 'test', 'subject' => (string) $daysAgo, 'created_at' => now()->subMonths($daysAgo)]);
                GuestSignIn::query()->create(['tenant_id' => 'cafe-a', 'kind' => GuestSignIn::KIND_WHATSAPP, 'code' => '123456',
                    'secret_hash' => GuestSignIn::hash('secret-'.$daysAgo), 'expires_at' => now()->subDays($daysAgo)]);
            }
        });
        foreach ([31, 1] as $monthsAgo) {
            PlatformAuditLog::query()->create(['action' => 'test', 'subject' => (string) $monthsAgo, 'created_at' => now()->subMonths($monthsAgo)]);
        }

        Artisan::call('model:prune');

        $this->assertSame([1], DB::table('delivery_track_points')->pluck('id')->map(fn () => 1)->all());
        $this->assertSame(['1'], DB::table('audit_logs')->pluck('subject')->all());
        $this->assertSame(1, DB::table('guest_sign_ins')->count());
        $this->assertSame(['1'], DB::table('platform_audit_logs')->pluck('subject')->all());
    }
}
