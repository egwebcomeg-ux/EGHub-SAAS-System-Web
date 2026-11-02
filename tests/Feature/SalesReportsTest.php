<?php

namespace Tests\Feature;

use App\Models\CallDevice;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Services\Crm\CallLog;
use Tests\TenantTestCase;

/** «تقارير المبيعات»: the numbers of a period, who may see whose, the CSV and the printed files. */
class SalesReportsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['eghub.admin_require_2fa' => false]);
    }

    private function admin(string $role, string $name): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@egweb.test', 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    private function logCall(PlatformAdmin $admin, string $id, string $direction, int $seconds, ?int $at = null): void
    {
        $device = CallDevice::query()->firstOrCreate(['admin_id' => $admin->id], ['name' => 'A24', 'token_hash' => hash('sha256', $admin->email)]);
        app(CallLog::class)->store($device, [['id' => $id, 'phone' => '0403331234', 'direction' => $direction, 'started_at' => $at ?? now()->getTimestampMs(), 'duration' => $seconds]]);
    }

    public function test_the_report_counts_the_period_only_and_gives_each_person_their_numbers(): void
    {
        $mona = $this->admin('sales', 'Mona Caller');
        $ali = $this->admin('sales', 'Ali Caller');
        $this->logCall($mona, 'a', 'outgoing', 60);
        $this->logCall($mona, 'b', 'outgoing', 120);
        $this->logCall($ali, 'c', 'missed', 0);
        $this->logCall($mona, 'old', 'outgoing', 500, now()->subMonths(2)->getTimestampMs());
        $place = Prospect::query()->create(['name' => 'مطعم', 'category' => 'restaurant', 'phone' => '01066666666', 'lost_reason' => 'price']);
        $place->events()->create(['type' => 'stage', 'admin_id' => $mona->id, 'meta' => ['from' => 'new', 'to' => 'lost']]);
        $range = ['from' => now()->subDay()->format('Y-m-d'), 'to' => now()->addDay()->format('Y-m-d')];

        $this->actingAs($this->admin('owner', 'The Owner'), 'admin');
        $team = $this->get($this->adminUrl('/crm/reports?'.http_build_query($range)))->assertOk()->assertSee('السعر');
        $this->assertSame(3, $team->viewData('report')['calls']['total']);
        $this->assertSame(180, $team->viewData('report')['calls']['seconds']);
        $this->assertSame(90, $team->viewData('report')['calls']['average']);
        $this->assertSame(1, $team->viewData('report')['calls']['not_returned']);

        $one = $this->get($this->adminUrl('/crm/reports?'.http_build_query($range + ['admin' => $ali->id])))->assertOk();
        $this->assertSame(1, $one->viewData('report')['calls']['total']);
    }

    public function test_a_sales_person_cannot_read_someone_elses_report_and_a_field_rep_has_none(): void
    {
        $mona = $this->admin('sales', 'Mona Caller');
        $ali = $this->admin('sales', 'Ali Caller');
        $this->logCall($ali, 'c', 'outgoing', 60);

        $this->actingAs($mona, 'admin');
        $own = $this->get($this->adminUrl('/crm/reports?admin='.$ali->id))->assertOk();
        $this->assertSame(0, $own->viewData('report')['calls']['total']);
        $this->assertSame($mona->id, $own->viewData('person')->id);
        $this->assertStringContainsString('Mona Caller', $this->get($this->adminUrl('/crm/reports/export.csv?admin='.$ali->id))->assertOk()->streamedContent());

        $this->actingAs($this->admin('field_sales', 'Omar Field'), 'admin');
        $this->get($this->adminUrl('/crm/reports'))->assertForbidden();
    }

    public function test_the_csv_and_print_pages_carry_the_same_numbers(): void
    {
        $owner = $this->admin('owner', 'The Owner');
        $this->logCall($owner, 'a', 'outgoing', 65);
        $this->actingAs($owner, 'admin');

        $csv = $this->get($this->adminUrl('/crm/reports/export.csv'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('01:05', $csv);
        $this->get($this->adminUrl('/crm/reports/print'))->assertOk()->assertSee('01:05')->assertSee('window.print()', false);
    }

    public function test_the_printed_file_shows_the_timeline_and_follows_who_may_see_the_place(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $lead = Lead::for($tenant);
        $lead->events()->create(['type' => 'note', 'notes' => 'كلمه بعد العيد']);
        $omar = $this->admin('field_sales', 'Omar Field');
        $ali = $this->admin('field_sales', 'Ali Field');
        $place = Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'field_id' => $omar->id]);
        $place->events()->create(['type' => 'note', 'notes' => 'صاحبه موجود الصبح']);

        $this->actingAs($this->admin('owner', 'The Owner'), 'admin');
        $this->get($this->adminUrl("/crm/leads/{$lead->id}/print"))->assertOk()->assertSee($tenant->name)->assertSee('كلمه بعد العيد');
        $this->get($this->adminUrl("/crm/leads/{$lead->id}"))->assertOk()->assertSee("/crm/leads/{$lead->id}/print", false);

        $this->actingAs($omar, 'admin');
        $this->get($this->adminUrl("/crm/prospects/{$place->id}/print"))->assertOk()->assertSee('صاحبه موجود الصبح');
        $this->get($this->adminUrl("/crm/leads/{$lead->id}/print"))->assertForbidden();

        $this->actingAs($ali, 'admin');
        $this->get($this->adminUrl("/crm/prospects/{$place->id}/print"))->assertNotFound();
    }
}
