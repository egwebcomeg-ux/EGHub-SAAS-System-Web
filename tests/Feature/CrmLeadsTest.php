<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Notifications\NewSignup;
use App\Services\Crm\CallScript;
use App\Services\Crm\LeadBackfill;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** The admin's customer follow-up: every restaurant is a lead, calls are logged, a payment wins it, permissions gate it. */
class CrmLeadsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CallScript::installDefaults();
    }

    private function admin(string $role = 'owner', array $extra = []): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => ucfirst($role).' Admin', 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123',
            'role' => $role, 'permissions' => $extra ?: null,
        ]);
    }

    public function test_a_sign_up_becomes_a_new_lead_and_the_owners_are_emailed(): void
    {
        Notification::fake();
        $owner = $this->admin();
        $support = $this->admin('support');

        $this->post($this->menuUrl('register'), [
            'name' => 'كافيه جديد', 'slug' => 'new-cafe', 'owner_name' => 'Sara Ali', 'email' => 'sara@new.test', 'business_type' => 'restaurant',
            'phone' => '01066666666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertRedirect();

        $lead = Lead::query()->where('tenant_id', 'new-cafe')->first();
        $this->assertSame(['new', 'signup'], [$lead->stage, $lead->source]);
        Notification::assertSentTo($owner, NewSignup::class);
        Notification::assertNotSentTo($support, NewSignup::class);
    }

    public function test_a_restaurant_made_by_the_team_is_a_manual_lead_followed_by_its_creator(): void
    {
        $owner = $this->admin();
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/tenants'), [
            'name' => 'Prospect', 'slug' => 'prospect', 'owner_name' => 'Ali', 'email' => '', 'phone' => '', 'plan' => 'full', 'trial_days' => 14,
        ])->assertRedirect();

        $lead = Lead::query()->where('tenant_id', 'prospect')->first();
        $this->assertSame(['manual', $owner->id], [$lead->source, $lead->assigned_admin_id]);
    }

    public function test_backfill_makes_leads_for_older_restaurants_and_marks_payers_won(): void
    {
        $paying = $this->restaurant('paying', 'free');
        $quiet = $this->restaurant('quiet', 'free');
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($paying, Plan::query()->where('code', 'full')->first(), 1);
        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'k1');
        Lead::query()->delete();

        $this->assertSame(2, app(LeadBackfill::class)->run());
        $this->assertSame(0, app(LeadBackfill::class)->run());
        $this->assertSame('won', Lead::query()->where('tenant_id', 'paying')->value('stage'));
        $this->assertSame('new', Lead::query()->where('tenant_id', $quiet->id)->value('stage'));
    }

    public function test_paying_a_subscription_invoice_wins_the_lead(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($tenant, Plan::query()->where('code', 'full')->first(), 1);

        $billing->markPaid($invoice, 'manual', null, $invoice->total, 'admin:'.$invoice->id);

        $lead = Lead::for($tenant);
        $this->assertSame('won', $lead->stage);
        $this->assertNotNull($lead->won_at);
        $this->assertSame(1, $lead->events()->where('type', 'won')->count());
    }

    public function test_the_call_screen_shows_the_phone_the_script_filled_in_and_real_activity(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full', true);
        $this->owner($tenant)->update(['name' => 'Karim Adel', 'phone' => '01012345678']);
        $lead = Lead::for($tenant);
        $price = money(Plan::query()->where('code', 'full')->value('monthly_price'));

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/crm/leads/'.$lead->id))->assertOk()
            ->assertSee('href="tel:01012345678"', false)
            ->assertSee('https://wa.me/201012345678?text=', false)
            ->assertSee('أهلاً يا أستاذ Karim')
            ->assertSee($price.' جنيه في الشهر')
            ->assertDontSee('{full_price}')
            ->assertSee('data-signal="items" data-state="warn"', false);
    }

    public function test_logging_a_call_saves_the_answers_moves_a_new_lead_and_sets_the_follow_up(): void
    {
        $admin = $this->admin('sales');
        $lead = Lead::for($this->restaurant('cafe-a'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/crm/leads/'.$lead->id.'/calls'), [
            'channel' => 'call', 'outcome' => 'answered', 'notes' => 'Wants delivery',
            'answers' => ['q_why' => 'delivery', 'q_menu' => 'not-an-option'], 'objections' => ['obj_price'],
            'next_follow_up_at' => local_at()->addDays(2)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $lead->refresh();
        $this->assertSame('contacted', $lead->stage);
        $this->assertSame(['q_why' => 'delivery'], $lead->answers);
        $this->assertSame($admin->id, $lead->assigned_admin_id);
        $this->assertNotNull($lead->next_follow_up_at);
        $event = $lead->events()->first();
        $this->assertSame(['call', 'answered', ['obj_price']], [$event->type, $event->outcome, $event->objections]);
    }

    public function test_lost_needs_a_reason_and_callback_needs_a_date(): void
    {
        $admin = $this->admin('sales');
        $lead = Lead::for($this->restaurant('cafe-a'));
        $url = $this->adminUrl('/crm/leads/'.$lead->id);

        $this->actingAs($admin, 'admin')->post($url, ['stage' => 'lost'])->assertSessionHasErrors('lost_reason');
        $this->actingAs($admin, 'admin')->post($url, ['stage' => 'callback'])->assertSessionHasErrors('next_follow_up_at');
        $this->actingAs($admin, 'admin')->post($url, ['stage' => 'lost', 'lost_reason' => 'price'])->assertSessionHasNoErrors();

        $this->assertSame(['lost', 'price'], [$lead->fresh()->stage, $lead->fresh()->lost_reason]);
        $this->assertSame(1, $lead->events()->where('type', 'stage')->count());
    }

    public function test_won_cannot_be_set_by_hand(): void
    {
        $lead = Lead::for($this->restaurant('cafe-a'));

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/crm/leads/'.$lead->id), ['stage' => 'won'])->assertSessionHasErrors('stage');
        $this->assertSame('new', $lead->fresh()->stage);
    }

    public function test_the_list_filters_due_follow_ups_and_searches_by_mobile(): void
    {
        $admin = $this->admin();
        $due = Lead::for($this->restaurant('due-cafe'));
        $due->update(['stage' => 'callback', 'next_follow_up_at' => now()->subDays(2)]);
        $later = Lead::for($this->restaurant('later-cafe'));
        $later->update(['stage' => 'callback', 'next_follow_up_at' => now()->addDays(3)]);
        $this->owner(Tenant::query()->find('later-cafe'))->update(['phone' => '01099998888']);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/crm?due=overdue'))->assertOk()
            ->assertSee('data-lead-row="'.$due->id.'"', false)->assertDontSee('data-lead-row="'.$later->id.'"', false);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/crm?q=01099998888'))->assertOk()
            ->assertSee('data-lead-row="'.$later->id.'"', false)->assertDontSee('data-lead-row="'.$due->id.'"', false);
        // today's queue: the overdue follow-up first
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/'))->assertOk()->assertSee('data-crm-card', false)->assertSee('data-nav="admin.crm"', false);
    }

    public function test_view_only_admins_see_leads_but_cannot_change_them(): void
    {
        $viewer = $this->admin('finance', ['crm.view']);
        $lead = Lead::for($this->restaurant('cafe-a'));

        $this->actingAs($viewer, 'admin')->get($this->adminUrl('/crm'))->assertOk();
        $this->actingAs($viewer, 'admin')->get($this->adminUrl('/crm/leads/'.$lead->id))->assertOk()->assertDontSee('data-call-form', false);
        $this->actingAs($viewer, 'admin')->post($this->adminUrl('/crm/leads/'.$lead->id.'/calls'), ['channel' => 'call', 'outcome' => 'answered'])->assertForbidden();
        $this->actingAs($viewer, 'admin')->post($this->adminUrl('/crm/leads/'.$lead->id), ['stage' => 'contacted'])->assertForbidden();
        $this->assertSame(0, $lead->events()->count());
    }

    public function test_admins_without_crm_permissions_get_403(): void
    {
        $content = $this->admin('content');
        $lead = Lead::for($this->restaurant('cafe-a'));

        $this->actingAs($content, 'admin')->get($this->adminUrl('/'))->assertOk()->assertDontSee('data-nav="admin.crm"', false)->assertDontSee('data-crm-card', false);
        $this->actingAs($content, 'admin')->get($this->adminUrl('/crm'))->assertForbidden();
        $this->actingAs($content, 'admin')->get($this->adminUrl('/crm/leads/'.$lead->id))->assertForbidden();
    }
}
