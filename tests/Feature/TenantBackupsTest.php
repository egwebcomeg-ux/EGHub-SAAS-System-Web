<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Backups\TenantBackupBuilder;
use App\Services\Backups\TenantBackups;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TenantTestCase;
use ZipArchive;

/**
 * Per-restaurant backups: the zip holds one restaurant's rows and files only, never a password hash; owner only;
 * locked on the free plan; signed downloads scoped to the restaurant; one manual backup an hour; the retention; the
 * weekly command goes on when one restaurant fails.
 */
class TenantBackupsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function order(Tenant $tenant, array $attrs = []): Order
    {
        return $this->as($tenant, fn () => Order::query()->create($attrs + [
            'branch_id' => Branch::query()->value('id'), 'number' => (int) (Order::query()->max('number') ?? 0) + 1, 'status' => 'served', 'total' => 50,
        ]));
    }

    /** Builds a backup of $tenant through the service (as the weekly run does) and returns the row. */
    private function backup(Tenant $tenant): TenantBackup
    {
        return $this->as($tenant, fn () => app(TenantBackups::class)->runScheduled());
    }

    private function zip(TenantBackup $backup): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->storagePath())), 'the zip opens');

        return $zip;
    }

    private function signedDownload(int $id): string
    {
        return URL::temporarySignedRoute('dash.backups.download', now()->addMinutes(5), ['backup' => $id]);
    }

    public function test_the_zip_holds_only_the_restaurants_own_rows_and_files_and_no_secrets(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $this->item($a, 'Latte A');
        $this->item($b, 'Latte B');
        $this->as($a, fn () => Customer::query()->create(['name' => 'Guest A', 'phone' => '01000000001']));
        $this->as($b, fn () => Customer::query()->create(['name' => 'Guest B', 'phone' => '01000000002']));
        $this->as($a, fn () => Settings::put(['ai_key' => 'sk-very-secret-key', 'brand_name' => 'Cafe Alpha']));
        $recent = $this->order($a, ['customer_name' => 'Recent Guest']);
        $this->as($a, fn () => OrderItem::query()->create(['order_id' => $recent->id, 'name' => 'Recent Latte', 'unit_price' => 50, 'quantity' => 1, 'line_total' => 50]));
        $old = $this->order($a, ['customer_name' => 'Ancient Guest', 'created_at' => now()->subMonths(14), 'total' => 80]);
        $this->as($a, fn () => OrderItem::query()->create(['order_id' => $old->id, 'name' => 'Ancient Latte', 'unit_price' => 80, 'quantity' => 1, 'line_total' => 80]));
        $this->order($b, ['customer_name' => 'Guest Of B']);
        Storage::disk('public')->put('tenants/cafe-a/items/photo-a.webp', 'a-bytes');
        Storage::disk('public')->put('tenants/cafe-a/brand/logo.webp', 'logo-bytes');
        Storage::disk('public')->put('tenants/cafe-b/items/photo-b.webp', 'b-bytes');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/backups'))->assertRedirect()->assertSessionHasNoErrors();

        $backup = $this->as($a, fn () => TenantBackup::query()->latest('id')->firstOrFail());
        $this->assertSame(TenantBackup::STATUS_READY, $backup->status, (string) $backup->error);
        $this->assertSame(TenantBackup::KIND_MANUAL, $backup->kind);
        $this->assertSame($this->owner($a)->id, $backup->created_by);
        $this->assertGreaterThan(0, $backup->size);
        $this->assertNotNull($backup->expires_at);
        $this->assertSame(0, TenantBackup::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-b')->count());
        // the file is on the private disk, under the restaurant's own folder, never under public/
        $this->assertSame("backups/cafe-a/{$backup->filename}", $backup->storagePath());
        Storage::disk('local')->assertExists($backup->storagePath());
        Storage::disk('public')->assertMissing($backup->storagePath());

        $zip = $this->zip($backup);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('README.txt', $names);
        $this->assertContains('excel/menu.xlsx', $names);
        $this->assertContains('excel/customers.xlsx', $names);
        $this->assertContains('excel/orders.xlsx', $names);
        $this->assertContains('excel/corporate-vouchers.xlsx', $names);
        $this->assertContains('data/users.json', $names);
        $this->assertContains('data/orders-archive.json', $names);
        foreach ((new TenantBackupBuilder)->tenantModels() as $class) {
            $this->assertContains('data/'.(new $class)->getTable().'.json', $names, "$class is backed up");
        }
        // images: the restaurant's own folder only
        $this->assertContains('images/items/photo-a.webp', $names);
        $this->assertContains('images/brand/logo.webp', $names);
        $this->assertNotContains('images/items/photo-b.webp', $names);
        $this->assertSame('a-bytes', $zip->getFromName('images/items/photo-a.webp'));

        // rows: tenant A's only
        $items = json_decode($zip->getFromName('data/menu_items.json'), true);
        $this->assertSame(['Latte A'], array_column($items, 'name'));
        $this->assertSame(['cafe-a'], array_unique(array_column($items, 'tenant_id')));
        $customers = json_decode($zip->getFromName('data/customers.json'), true);
        $this->assertSame(['01000000001'], array_column($customers, 'phone'));
        $this->assertStringNotContainsString('Guest Of B', $zip->getFromName('data/orders.json'));

        // orders: the last 12 months row by row, the older ones as a monthly summary
        $orders = json_decode($zip->getFromName('data/orders.json'), true);
        $this->assertSame(['Recent Guest'], array_column($orders, 'customer_name'));
        $orderItems = json_decode($zip->getFromName('data/order_items.json'), true);
        $this->assertSame(['Recent Latte'], array_column($orderItems, 'name'));
        $archive = json_decode($zip->getFromName('data/orders-archive.json'), true);
        $this->assertCount(1, $archive);
        $this->assertSame(local_at(now()->subMonths(14))->format('Y-m'), $archive[0]['month']);
        $this->assertSame(1, $archive[0]['orders']);
        $this->assertEquals(80, $archive[0]['total']);

        // staff: name, email, role, branch; nothing that signs in
        $users = json_decode($zip->getFromName('data/users.json'), true);
        $this->assertCount(1, $users);
        $this->assertSame('owner@cafe-a.test', $users[0]['email']);
        $this->assertSame('owner', $users[0]['role']);
        $this->assertSame(['id', 'name', 'email', 'phone', 'role', 'extra_permissions', 'branch_id', 'is_active', 'locale', 'last_login_at', 'created_at'], array_keys($users[0]));
        $hash = $this->owner($a)->getAuthPassword();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = (string) $zip->getFromIndex($i);
            $this->assertStringNotContainsString($hash, $content, $zip->getNameIndex($i).' holds a password hash');
            $this->assertStringNotContainsString('sk-very-secret-key', $content, $zip->getNameIndex($i).' holds the AI key');
        }
        foreach (['password', 'remember_token', 'two_factor', 'manager_pin', 'phone_verification_code'] as $column) {
            $this->assertStringNotContainsString($column, $zip->getFromName('data/users.json'));
        }
        $settings = collect(json_decode($zip->getFromName('data/tenant_settings.json'), true))->pluck('value', 'key');
        $this->assertSame('[redacted]', $settings['ai_key']);
        $this->assertSame('Cafe Alpha', $settings['brand_name']);

        $readme = $zip->getFromName('README.txt');
        $this->assertStringContainsString('Cafe Alpha', $readme);
        $this->assertStringContainsString('cafe-a', $readme);
        $this->assertStringContainsString('menu.xlsx', $readme);
        $this->assertStringContainsString('EgWeb', $readme);
        $zip->close();

        $this->assertSame(['backup.created'], $this->as($a, fn () => AuditLog::query()->pluck('action')->all()));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/backups'))->assertOk()
            ->assertSee('data-backups-list', false)->assertSee('data-backup-download', false)->assertSee($backup->sizeLabel());
    }

    public function test_only_the_owner_opens_the_page_and_sees_the_tile(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->assertTrue($manager->can('settings.manage'));

        $this->actingAs($manager)->get($this->dashUrl('/backups'))->assertForbidden();
        $this->actingAs($manager)->post($this->dashUrl('/backups'))->assertForbidden();
        $this->actingAs($manager)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-app="dash.backups"', false);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/backups'))->assertOk()->assertSee(__('Create a backup now'))->assertDontSee('data-backups-locked', false);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()->assertSee('data-app="dash.backups"', false);
    }

    public function test_the_free_plan_sees_the_page_locked(): void
    {
        $a = $this->restaurant('cafe-a', 'free');
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/backups'))->assertOk()->assertSee('data-backups-locked', false)->assertDontSee(__('Create a backup now'));
        $this->actingAs($owner)->post($this->dashUrl('/backups'))->assertForbidden();
        $this->assertSame(0, TenantBackup::query()->withoutGlobalScopes()->count());
    }

    public function test_downloads_are_signed_and_scoped_to_the_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $mine = $this->backup($a);
        $theirs = $this->backup($b);
        $this->assertTrue($mine->isReady());
        $this->assertTrue($theirs->isReady());

        $this->get($this->signedDownload($mine->id))->assertRedirect($this->dashUrl('/login')); // not signed in
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->signedDownload($theirs->id))->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl("/backups/{$mine->id}/download"))->assertForbidden(); // no signature

        $response = $this->actingAs($owner)->get($this->signedDownload($mine->id))->assertOk();
        $this->assertStringContainsString('egmenu-cafe-a-'.$mine->filename, (string) $response->headers->get('content-disposition'));

        // delete: own backup goes (file and row), another restaurant's is not found
        $this->actingAs($owner)->post($this->dashUrl("/backups/{$theirs->id}/delete"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/backups/{$mine->id}/delete"))->assertRedirect();
        Storage::disk('local')->assertMissing($mine->storagePath());
        Storage::disk('local')->assertExists($theirs->storagePath());
        $this->assertSame(0, $this->as($a, fn () => TenantBackup::query()->count()));
        $this->assertSame(['backup.downloaded', 'backup.deleted'], $this->as($a, fn () => AuditLog::query()->orderBy('id')->pluck('action')->all()));
    }

    public function test_one_manual_backup_an_hour(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/backups'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl('/backups'))->assertSessionHasErrors('backup');
        $this->assertSame(1, $this->as($a, fn () => TenantBackup::query()->count()));
        $this->actingAs($owner)->get($this->dashUrl('/backups'))->assertOk()->assertSee('data-backup-blocked', false);

        $this->travel(61)->minutes();
        $this->actingAs($owner)->post($this->dashUrl('/backups'))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->as($a, fn () => TenantBackup::query()->count()));
    }

    public function test_prune_keeps_the_last_four_weekly_backups_and_manual_ones_for_thirty_days(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $rows = [];
        $this->as($a, function () use (&$rows) {
            $make = function (string $kind, int $daysAgo, string $status = 'ready') use (&$rows) {
                $row = TenantBackup::query()->create([
                    'filename' => "$kind-$daysAgo.zip", 'kind' => $kind, 'status' => $status, 'size' => 10,
                    'created_at' => now()->subDays($daysAgo), 'expires_at' => $kind === 'manual' ? now()->subDays($daysAgo)->addDays(30) : null,
                ]);
                Storage::disk('local')->put($row->storagePath(), 'zip');
                $rows[$row->filename] = $row;
            };
            foreach ([35, 28, 21, 14, 7, 0] as $d) {
                $make('scheduled', $d);
            }
            $make('manual', 31);
            $make('manual', 29);
            $make('scheduled', 3, 'failed');
            $make('scheduled', 2, 'building');
        });

        $gone = $this->as($a, fn () => app(TenantBackups::class)->prune());

        $left = $this->as($a, fn () => TenantBackup::query()->orderBy('id')->pluck('status', 'filename')->all());
        $this->assertSame([
            'scheduled-21.zip' => 'ready', 'scheduled-14.zip' => 'ready', 'scheduled-7.zip' => 'ready', 'scheduled-0.zip' => 'ready',
            'manual-29.zip' => 'ready', 'scheduled-2.zip' => 'failed',
        ], $left);
        $this->assertSame(4, $gone);
        Storage::disk('local')->assertMissing($rows['scheduled-35.zip']->storagePath());
        Storage::disk('local')->assertMissing($rows['manual-31.zip']->storagePath());
        Storage::disk('local')->assertExists($rows['scheduled-21.zip']->storagePath());
        Storage::disk('local')->assertExists($rows['manual-29.zip']->storagePath());
    }

    public function test_the_weekly_command_backs_up_the_plans_with_the_feature_and_prunes(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'enterprise');
        $free = $this->restaurant('cafe-free', 'free');
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->item($a, 'Latte A');
        $this->as($free, fn () => TenantBackup::query()->create(['filename' => 'old.zip', 'kind' => 'manual', 'status' => 'ready', 'created_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]));

        $this->artisan('egmenu:tenant-backups')->assertSuccessful()
            ->expectsOutputToContain('built: 2 · failed: 0 · not in plan: 2 · pruned: 1');

        foreach ([$a, $b] as $t) {
            $backup = $this->as($t, fn () => TenantBackup::query()->sole());
            $this->assertSame(TenantBackup::KIND_SCHEDULED, $backup->kind);
            $this->assertTrue($backup->isReady(), (string) $backup->error);
            $this->assertNull($backup->created_by);
            $this->assertNull($backup->expires_at);
            Storage::disk('local')->assertExists($backup->storagePath());
        }
        $this->assertSame(0, $this->as($free, fn () => TenantBackup::query()->count()));
        $this->assertSame(0, $this->as($pro, fn () => TenantBackup::query()->count()));
        $this->assertNotNull(cache()->get(TenantBackups::LAST_RUN_KEY));

        Artisan::call('egmenu:doctor');
        $this->assertStringContainsString('OK    Restaurant backups ran within 8 days', Artisan::output());
    }

    public function test_the_weekly_command_survives_one_restaurant_failing(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $c = $this->restaurant('cafe-c', 'full');
        $this->mock(TenantBackupBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->times(3)->andReturnUsing(function (TenantBackup $backup) {
                if ($backup->tenant_id === 'cafe-b') {
                    throw new \RuntimeException('disk is full');
                }
                Storage::disk('local')->put($backup->storagePath(), 'zip');

                return ['tables' => [], 'images' => 0, 'images_skipped' => 0, 'orders_archived_months' => 0, 'size' => 3];
            });
        });

        $this->artisan('egmenu:tenant-backups')->assertSuccessful()
            ->expectsOutputToContain('built: 2 · failed: 1 · not in plan: 0 · pruned: 0');

        $this->assertTrue($this->as($a, fn () => TenantBackup::query()->sole())->isReady());
        $this->assertTrue($this->as($c, fn () => TenantBackup::query()->sole())->isReady());
        $failed = $this->as($b, fn () => TenantBackup::query()->sole());
        $this->assertSame(TenantBackup::STATUS_FAILED, $failed->status);
        $this->assertSame('disk is full', $failed->error);
        Storage::disk('local')->assertMissing($failed->storagePath());
        $this->assertNotNull(cache()->get(TenantBackups::LAST_RUN_KEY));
    }

    public function test_doctor_warns_when_the_weekly_run_is_stale(): void
    {
        Artisan::call('egmenu:doctor');
        $this->assertStringContainsString('WARN  Restaurant backups ran within 8 days - no weekly run recorded yet', Artisan::output());

        cache()->forever(TenantBackups::LAST_RUN_KEY, now()->subDays(9)->timestamp);
        Artisan::call('egmenu:doctor');
        $this->assertStringContainsString('WARN  Restaurant backups ran within 8 days - last ', Artisan::output());

        cache()->forever(TenantBackups::LAST_RUN_KEY, now()->subDays(2)->timestamp);
        Artisan::call('egmenu:doctor');
        $this->assertStringContainsString('OK    Restaurant backups ran within 8 days', Artisan::output());
    }
}
