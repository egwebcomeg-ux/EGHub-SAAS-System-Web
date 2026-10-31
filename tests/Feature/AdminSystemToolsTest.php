<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The admin's platform tools: retrying or forgetting a failed background job from «صحة النظام» (settings.manage,
 * written in the activity log), the activity log's readable action names, and the header avatar's initials.
 */
class AdminSystemToolsTest extends TenantTestCase
{
    private function admin(string $role, string $name = 'Sara Admin'): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => Str::slug($name)."-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    private function failedJob(): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\SendPushNotification', 'attempts' => 3]),
            'exception' => "RuntimeException: FCM down\n#0 trace", 'failed_at' => now()]);

        return $uuid;
    }

    public function test_a_failed_job_is_sent_back_to_its_queue_and_logged(): void
    {
        $uuid = $this->failedJob();
        $owner = $this->admin('owner');

        $this->actingAs($owner, 'admin')->get($this->adminUrl('/system'))->assertOk()->assertSee('data-failed-job="'.$uuid.'"', false);
        $this->actingAs($owner, 'admin')->post($this->adminUrl("/system/failed-jobs/$uuid/retry"))->assertRedirect(route('admin.system'));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertDatabaseHas('platform_audit_logs', ['admin_id' => $owner->id, 'action' => 'failed_job.retried', 'subject' => 'App\\Jobs\\SendPushNotification']);
    }

    public function test_a_failed_job_is_forgotten_and_logged(): void
    {
        $uuid = $this->failedJob();
        $owner = $this->admin('owner');

        $this->actingAs($owner, 'admin')->post($this->adminUrl("/system/failed-jobs/$uuid/delete"))->assertRedirect(route('admin.system'));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('platform_audit_logs', ['admin_id' => $owner->id, 'action' => 'failed_job.deleted']);
    }

    public function test_only_the_platform_settings_admins_touch_failed_jobs(): void
    {
        $uuid = $this->failedJob();
        $manager = $this->admin('manager', 'Mido Manager');

        $this->actingAs($manager, 'admin')->post($this->adminUrl("/system/failed-jobs/$uuid/retry"))->assertForbidden();
        $this->actingAs($manager, 'admin')->post($this->adminUrl("/system/failed-jobs/$uuid/delete"))->assertForbidden();
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        $this->actingAs($this->admin('owner'), 'admin')->post($this->adminUrl('/system/failed-jobs/'.Str::uuid().'/retry'))->assertNotFound();
        $this->assertSame(0, PlatformAuditLog::query()->count());
    }

    public function test_the_activity_log_names_known_actions_in_arabic_and_shows_unknown_ones_as_they_are(): void
    {
        $owner = $this->admin('owner');
        PlatformAuditLog::record($owner, 'plan.archived', 'starter');
        PlatformAuditLog::record($owner, 'something.new', 'x');

        $this->actingAs($owner, 'admin')->get($this->adminUrl('/activity'))->assertOk()
            ->assertSee('أرشفة باقة')->assertSee('>something.new</span>', false);
    }

    public function test_the_avatar_shows_the_first_letters_of_arabic_and_english_names(): void
    {
        $this->actingAs($this->admin('owner', 'سارة  محمد علي'), 'admin')->get($this->adminUrl('/security'))->assertOk()
            ->assertSee("data-admin-initials>س\u{200C}م</span>", false);
        $this->actingAs($this->admin('owner', 'sara ali'), 'admin')->get($this->adminUrl('/security'))->assertOk()
            ->assertSee("data-admin-initials>S\u{200C}A</span>", false);
    }
}
