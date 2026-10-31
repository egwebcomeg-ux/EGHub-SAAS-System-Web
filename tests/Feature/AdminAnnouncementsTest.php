<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use Tests\TenantTestCase;

/**
 * «إعلانات للمطاعم»: written in the admin (tenants.manage, in the activity log), shown as a banner on the dashboards of
 * the restaurants it is aimed at (all, or one plan's) while it is live, and hidden per user.
 */
class AdminAnnouncementsTest extends TenantTestCase
{
    private function admin(string $role, string $name = 'Soha Support'): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name))."-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    public function test_the_team_writes_an_announcement_for_one_plan_and_it_is_logged(): void
    {
        $support = $this->admin('support');

        $this->actingAs($support, 'admin')->post($this->adminUrl('/announcements'), [
            'title' => 'صيانة الليلة', 'body' => 'السيستم هيقف نص ساعة بعد منتصف الليل.', 'level' => 'warning',
            'plan_id' => $this->plan('full')->id, 'starts_at' => '2026-11-01T10:00', 'ends_at' => '2026-11-02T10:00',
        ])->assertRedirect(route('admin.announcements'));

        $announcement = Announcement::query()->sole();
        $this->assertSame($this->plan('full')->id, $announcement->plan_id);
        $this->assertSame(local_input('2026-11-01T10:00')->toDateTimeString(), $announcement->starts_at->toDateTimeString());
        $this->assertDatabaseHas('platform_audit_logs', ['admin_id' => $support->id, 'action' => 'announcement.created', 'subject' => 'صيانة الليلة']);
        $this->actingAs($support, 'admin')->get($this->adminUrl('/announcements'))->assertOk()->assertSee('data-announcement-row="'.$announcement->id.'"', false);
    }

    public function test_an_announcement_must_end_after_it_starts_and_needs_the_restaurants_right(): void
    {
        $this->actingAs($this->admin('support'), 'admin')->post($this->adminUrl('/announcements'), [
            'title' => 'T', 'body' => 'B', 'level' => 'info', 'starts_at' => '2026-11-05T10:00', 'ends_at' => '2026-11-04T10:00',
        ])->assertSessionHasErrors('ends_at');
        $this->actingAs($this->admin('content', 'Nour Content'), 'admin')->post($this->adminUrl('/announcements'), [
            'title' => 'T', 'body' => 'B', 'level' => 'info',
        ])->assertForbidden();
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_a_restaurant_sees_only_the_live_announcements_aimed_at_it(): void
    {
        $pro = $this->restaurant('cafe-pro');
        $full = $this->restaurant('cafe-full', plan: 'full');
        Announcement::factory()->create(['title' => 'For everyone']);
        Announcement::factory()->forPlan($this->plan('full'))->create(['title' => 'Complete plan only']);
        Announcement::factory()->ended()->create(['title' => 'Already over']);
        Announcement::factory()->scheduled()->create(['title' => 'Not started yet']);

        $this->actingAs($this->owner($pro))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('For everyone')->assertDontSee('Complete plan only')->assertDontSee('Already over')->assertDontSee('Not started yet');
        $this->actingAs($this->owner($full))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('For everyone')->assertSee('Complete plan only');
    }

    public function test_hiding_an_announcement_is_per_user_and_only_for_one_aimed_at_the_restaurant(): void
    {
        $pro = $this->restaurant('cafe-pro');
        $other = $this->restaurant('cafe-other');
        $everyone = Announcement::factory()->create(['title' => 'For everyone']);
        $fullOnly = Announcement::factory()->forPlan($this->plan('full'))->create(['title' => 'Complete plan only']);
        $cashier = $this->staff($pro, 'cashier', 'cashier@cafe-pro.test');

        $this->actingAs($this->owner($pro))->post($this->dashUrl("/announcements/{$everyone->id}/dismiss"))->assertRedirect();
        $this->actingAs($this->owner($pro))->post($this->dashUrl("/announcements/{$fullOnly->id}/dismiss"))->assertNotFound();

        $this->assertDatabaseCount('announcement_dismissals', 1);
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/'))->assertOk()->assertDontSee('For everyone');
        $this->actingAs($cashier)->get($this->dashUrl('/'))->assertOk()->assertSee('For everyone');
        $this->actingAs($this->owner($other))->get($this->dashUrl('/'))->assertOk()->assertSee('For everyone');
    }
}
