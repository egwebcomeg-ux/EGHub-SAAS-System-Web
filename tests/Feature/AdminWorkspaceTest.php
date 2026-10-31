<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Prospect;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * The admin panel by area of work: the sidebar groups each role sees, the home that starts with today's work of
 * that role, the header search filtered by permission, the activity log and the system health page.
 */
class AdminWorkspaceTest extends TenantTestCase
{
    private function admin(string $role, string $name = 'Sara Admin'): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name))."-$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    private function place(array $attributes = []): Prospect
    {
        return Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'city' => 'طنطا', 'governorate' => 'الغربية', ...$attributes]);
    }

    public function test_the_menu_is_grouped_by_area_and_each_role_sees_only_its_links(): void
    {
        $this->actingAs($this->admin('owner'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSeeInOrder([__('Customers'), __('Sales'), __('Plans & prices'), __('Content & marketing'), __('Platform')])
            ->assertSee('data-nav="admin.activity"', false)->assertSee('data-nav="admin.system"', false)->assertSee('data-nav="admin.prices"', false);

        $this->actingAs($this->admin('field_sales', 'Hany Field'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-nav="admin.crm.visits"', false)->assertSee('data-nav="admin.crm.playbook"', false)
            ->assertDontSee('data-nav="admin.tenants"', false)->assertDontSee('data-nav="admin.invoices"', false)
            ->assertDontSee('data-nav="admin.activity"', false)->assertDontSee('data-nav="admin.system"', false)
            ->assertDontSee(__('Customers'));
    }

    public function test_my_visits_is_promoted_to_field_reps_only_not_to_the_owner(): void
    {
        $tab = 'href="'.route('admin.crm.visits').'"';
        $this->actingAs($this->admin('owner', 'Omar Owner'), 'admin');
        $this->get($this->adminUrl('/'))->assertOk()->assertDontSee('data-nav="admin.crm.visits"', false);
        $this->get($this->adminUrl('/crm/prospects'))->assertOk()->assertDontSee($tab, false);
        $this->get($this->adminUrl('/crm/visits'))->assertOk(); // may still look

        $this->actingAs($this->admin('field_sales', 'Hany Field'), 'admin');
        $this->get($this->adminUrl('/crm/prospects'))->assertOk()->assertSee($tab, false);
    }

    public function test_a_field_rep_starts_on_todays_visits_and_the_month_against_the_targets(): void
    {
        $rep = $this->admin('field_sales', 'Hany Field');
        Appointment::query()->create(['prospect_id' => $this->place(['name' => 'كافيه النيل'])->id, 'field_id' => $rep->id,
            'scheduled_at' => local_day_start()->addMinute(), 'status' => 'scheduled']);
        Appointment::query()->create(['prospect_id' => $this->place(['name' => 'حلواني أمس'])->id, 'field_id' => $rep->id,
            'scheduled_at' => local_day_start()->subHours(3), 'status' => 'scheduled']);
        $this->restaurant('cafe-a');

        $this->actingAs($rep, 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-workspace="field"', false)->assertSee('كافيه النيل')->assertDontSee('حلواني أمس')
            ->assertSee(__(':n visits from earlier days still need a report', ['n' => 1]))
            ->assertSee('data-progress-card="'.$rep->id.'"', false)
            ->assertDontSee('data-kpis="tenants"', false)->assertDontSee(__('MRR'))->assertDontSee('data-crm-card', false);
    }

    public function test_the_home_lists_what_needs_attention_for_the_roles_that_handle_it(): void
    {
        $trial = $this->restaurant('cafe-t', trial: true);
        Subscription::query()->where('tenant_id', $trial->id)->update(['trial_ends_at' => now()->addDays(3)]);
        $invoice = Invoice::query()->create(['tenant_id' => $trial->id, 'number' => 'EGM-0042', 'kind' => 'subscription', 'lines' => [], 'subtotal' => 399, 'total' => 399, 'status' => 'open']);
        Payment::query()->create(['invoice_id' => $invoice->id, 'gateway' => 'instapay', 'reference' => 'TX-1', 'idempotency_key' => 'k-1', 'amount' => 399, 'status' => 'pending']);
        ServiceRequest::query()->create(['service' => 'print', 'name' => 'Mona', 'restaurant' => 'Rosto', 'phone' => '01012345678', 'status' => 'new']);

        $this->actingAs($this->admin('owner'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-attention-item="trials"', false)->assertSee('data-attention-item="transfers"', false)
            ->assertSee('data-attention-item="services"', false)->assertDontSee('data-attention-item="past_due"', false) // nothing there
            ->assertDontSee('data-workspace="support"', false); // the owner reads the overview, not support's list
        $this->actingAs($this->admin('finance', 'Fady Finance'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-attention-item="transfers"', false)->assertDontSee('data-attention-item="services"', false);
        $this->actingAs($this->admin('support', 'Soha Support'), 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-workspace="support"', false)->assertSee('Rosto')->assertDontSee('data-attention-item="transfers"', false);
    }

    public function test_the_search_finds_only_what_the_admin_may_open(): void
    {
        $tenant = $this->restaurant('cafe-a');
        Invoice::query()->create(['tenant_id' => $tenant->id, 'number' => 'EGM-0042', 'kind' => 'subscription', 'lines' => [], 'subtotal' => 399, 'total' => 399, 'status' => 'open']);
        $rep = $this->admin('field_sales', 'Hany Field');
        $this->place(['name' => 'Cafe Nile', 'field_id' => $rep->id]);
        $this->place(['name' => 'Cafe Other']);
        $groups = fn (PlatformAdmin $admin, string $q) => collect($this->actingAs($admin, 'admin')->getJson($this->adminUrl('/search?q='.urlencode($q)))->assertOk()->json('groups'))
            ->mapWithKeys(fn (array $g) => [$g['label'] => array_column($g['items'], 'title')])->all();

        $this->assertSame([__('Restaurants') => ['Restaurant cafe-a'], __('Places') => ['Cafe Other', 'Cafe Nile']], $groups($this->admin('owner'), 'cafe'));
        $this->assertSame([__('Places') => ['Cafe Nile']], $groups($rep, 'cafe')); // his own place only, no restaurants
        $this->assertSame([__('Invoices') => ['EGM-0042']], $groups($this->admin('finance', 'Fady Finance'), 'EGM-0042'));
        $this->assertSame([], $groups($this->admin('sales', 'Mona Caller'), 'EGM-0042')); // no billing right
        $this->assertSame([], $groups($this->admin('content', 'Nour Content'), 'cafe'));
        $this->assertSame([], $groups($this->admin('owner', 'Other Owner'), 'c'));
    }

    public function test_the_activity_log_is_filtered_and_kept_for_who_manages_the_team(): void
    {
        $owner = $this->admin('owner');
        PlatformAuditLog::record($owner, 'pricing.updated', '', ['changed' => 2]);
        PlatformAuditLog::record($owner, 'plan.archived', 'starter');

        $this->actingAs($owner, 'admin')->get($this->adminUrl('/activity?action=plan.archived'))->assertOk()
            ->assertSee('starter')->assertDontSee('&quot;changed&quot;:2', false);
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/activity'))->assertOk()->assertSee('pricing.updated');
        $this->actingAs($this->admin('manager', 'Mido Manager'), 'admin')->get($this->adminUrl('/activity'))->assertForbidden();
    }

    public function test_system_health_shows_the_checks_and_failed_jobs_to_the_platform_settings_admins_only(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendPushNotification']), 'exception' => "RuntimeException: FCM down\n#0 trace", 'failed_at' => now()]);
        $owner = $this->admin('owner');

        $page = $this->actingAs($owner, 'admin')->get($this->adminUrl('/system'))->assertOk()
            ->assertSee('data-system-checks', false)->assertSee('APP_KEY set')->assertSee('SendPushNotification')
            ->assertSee('RuntimeException: FCM down')->assertDontSee('#0 trace');
        $this->assertStringNotContainsString((string) config('app.key'), $page->getContent());
        // the sidebar counts the failed job next to the link
        $this->assertMatchesRegularExpression('/data-nav="admin.system".*?>1<\/span>/s', $page->getContent());
        $this->actingAs($this->admin('manager', 'Mido Manager'), 'admin')->get($this->adminUrl('/system'))->assertForbidden();
    }
}
