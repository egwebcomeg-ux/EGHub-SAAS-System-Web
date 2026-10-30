<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\SalesTarget;
use App\Services\Crm\CallScript;
use App\Services\Crm\TeamStats;
use App\Support\SalesPlan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * The field sales team: places without an account, the map and file imports, visits with a check-in and a report,
 * the link to the restaurant that signs up, the commission of its payments, the targets and who sees what.
 */
class CrmFieldSalesTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CallScript::installDefaults();
    }

    private function admin(string $role, string $name): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@egweb.test', 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    private function place(array $attributes = []): Prospect
    {
        return Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'city' => 'طنطا', 'governorate' => 'الغربية',
            'lat' => 30.7865, 'lng' => 31.0004, ...$attributes]);
    }

    public function test_a_sign_up_with_a_places_mobile_links_it_and_gives_the_lead_to_its_caller(): void
    {
        Notification::fake();
        $caller = $this->admin('sales', 'Mona Caller');
        $place = $this->place(['phone' => '01066666666', 'caller_id' => $caller->id, 'stage' => 'appointment']);

        $this->post($this->menuUrl('register'), [
            'name' => 'مطعم الأصيل', 'slug' => 'aseel', 'owner_name' => 'Hany', 'email' => 'hany@aseel.test', 'business_type' => 'restaurant',
            'phone' => '+20 106 666 6666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertRedirect();

        $place->refresh();
        $this->assertSame(['aseel', 'trial'], [$place->tenant_id, $place->stage]);
        $this->assertSame($caller->id, Lead::query()->where('tenant_id', 'aseel')->value('assigned_admin_id'));
        $this->assertTrue($place->events()->where('type', 'linked')->exists());
    }

    public function test_the_first_paid_invoice_wins_the_place_and_pays_the_closer_and_the_assist(): void
    {
        $caller = $this->admin('sales', 'Mona Caller');
        $field = $this->admin('field_sales', 'Omar Field');
        $tenant = $this->restaurant('aseel', 'free');
        $place = $this->place(['tenant_id' => $tenant->id, 'stage' => 'trial', 'caller_id' => $caller->id, 'field_id' => $field->id]);
        $place->appointments()->create(['set_by' => $caller->id, 'field_id' => $field->id, 'scheduled_at' => now()->subDay(),
            'status' => 'done', 'checked_in_at' => now()->subDay(), 'reported_at' => now()->subDay()]);

        $billing = app(Billing::class);
        $full = Plan::query()->where('code', 'full')->first();
        $first = $billing->invoiceForPlan($tenant, $full, 1);
        $billing->markPaid($first, 'manual', null, $first->total, 'k1');
        $second = $billing->invoiceForPlan($tenant->fresh(), $full, 1);
        $billing->markPaid($second, 'manual', null, $second->total, 'k2');

        $place->refresh();
        $this->assertSame(['won', $field->id, $caller->id], [$place->stage, $place->closer_id, $place->assist_id]);
        $stats = app(TeamStats::class);
        $month = local_at()->format('Y-m');
        $this->assertEqualsWithDelta(SalesPlan::closerCommission($first->total) + SalesPlan::retentionCommission($second->total), $stats->commission($field, $month), 0.01);
        $this->assertEqualsWithDelta(SalesPlan::assistCommission($first->total), $stats->commission($caller, $month), 0.01);
        // the floor and the ceiling of the closer's share
        $this->assertSame([75.0, 1500.0, 187.5], [SalesPlan::closerCommission(100), SalesPlan::closerCommission(50000), SalesPlan::closerCommission(1250)]);
    }

    public function test_a_field_rep_sees_only_his_own_places_and_never_the_sign_ups(): void
    {
        $omar = $this->admin('field_sales', 'Omar Field');
        $ali = $this->admin('field_sales', 'Ali Field');
        $mine = $this->place(['name' => 'Mine Grill', 'field_id' => $omar->id]);
        $his = $this->place(['name' => 'His Cafe', 'field_id' => $ali->id, 'phone' => '01011111111']);

        $this->actingAs($omar, 'admin');
        $this->get($this->adminUrl('/crm/prospects'))->assertOk()->assertSee('Mine Grill')->assertDontSee('His Cafe');
        $this->get($this->adminUrl("/crm/prospects/{$mine->id}"))->assertOk();
        $this->get($this->adminUrl("/crm/prospects/{$his->id}"))->assertNotFound();
        $this->post($this->adminUrl("/crm/prospects/{$his->id}"), ['name' => 'Taken', 'category' => 'cafe'])->assertNotFound();
        $this->post($this->adminUrl("/crm/prospects/{$mine->id}/calls"), ['channel' => 'call', 'outcome' => 'answered'])->assertForbidden();
        $this->get($this->adminUrl('/crm'))->assertRedirect(route('admin.crm.visits'));
        $this->get($this->adminUrl('/crm/team'))->assertForbidden();
        // a place he finds is his; a number another rep follows is not handed over
        $this->post($this->adminUrl('/crm/prospects'), ['name' => 'Street Bakery', 'category' => 'bakery', 'phone' => '01022222222'])->assertRedirect();
        $this->assertSame([$omar->id, 'field'], [Prospect::query()->where('name', 'Street Bakery')->value('field_id'), Prospect::query()->where('name', 'Street Bakery')->value('source')]);
        $this->post($this->adminUrl('/crm/prospects'), ['name' => 'Copy', 'category' => 'cafe', 'phone' => '01011111111'])->assertSessionHasErrors('phone');
    }

    public function test_the_map_import_reads_openstreetmap_scores_places_and_skips_known_ones(): void
    {
        $owner = $this->admin('owner', 'Owner');
        $this->place(['name' => 'Known', 'phone' => '01099999999']);
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 30.79, 'lon' => 31.0, 'tags' => ['amenity' => 'restaurant', 'name' => 'Kebab House', 'phone' => '+20 100 123 4567']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 30.791, 'lon' => 31.001], 'tags' => ['amenity' => 'cafe', 'name:ar' => 'كافيه النيل']],
            ['type' => 'node', 'id' => 3, 'lat' => 30.792, 'lon' => 31.002, 'tags' => ['shop' => 'pastry', 'name' => 'حلواني السلطان']],
            ['type' => 'node', 'id' => 4, 'lat' => 30.793, 'lon' => 31.003, 'tags' => ['amenity' => 'restaurant']],
            ['type' => 'node', 'id' => 5, 'lat' => 30.794, 'lon' => 31.004, 'tags' => ['amenity' => 'fast_food', 'name' => 'Same phone', 'phone' => '01099999999']],
        ]])]);

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/crm/import/map'), ['preset' => 'tanta', 'categories' => ['restaurant', 'cafe', 'sweets', 'bakery']])
            ->assertRedirect()->assertSessionHas('ok');

        $kebab = Prospect::query()->where('external_ref', 'osm:node/1')->first();
        $this->assertSame(['restaurant', '01001234567', 'طنطا', 'map'], [$kebab->category, $kebab->phone, $kebab->city, $kebab->source]);
        $this->assertSame('cafe', Prospect::query()->where('external_ref', 'osm:way/2')->value('category'));
        $this->assertSame('sweets', Prospect::query()->where('external_ref', 'osm:node/3')->value('category'));
        $this->assertSame(4, Prospect::query()->count()); // Known + 3 new: nameless and duplicate phone skipped
        $this->assertGreaterThan(Prospect::query()->where('external_ref', 'osm:way/2')->value('score'), $kebab->score); // the phone counts
        Http::assertSent(fn ($request) => str_contains($request['data'], 'amenity') && str_contains($request->header('User-Agent')[0], 'EgHub'));

        $this->post($this->adminUrl('/crm/import/map'), ['preset' => 'tanta', 'categories' => ['restaurant', 'cafe', 'sweets']]);
        $this->assertSame(4, Prospect::query()->count());
    }

    public function test_a_csv_from_google_maps_is_imported_with_its_kinds(): void
    {
        $caller = $this->admin('sales', 'Mona Caller');
        $csv = "title,phoneNumber,latitude,longitude,category\nفرن الحاج,01033333333,30.78,31.0,مخبز\nSweet Corner,,30.77,31.01,Dessert shop\nفرن الحاج,01033333333,30.78,31.0,مخبز\n,0100,1,1,x\n";

        $this->actingAs($caller, 'admin')->post($this->adminUrl('/crm/import/csv'), ['file' => UploadedFile::fake()->createWithContent('places.csv', $csv), 'city' => 'طنطا'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(['bakery', 'sweets'], Prospect::query()->orderBy('id')->pluck('category')->all());
        $this->assertSame('01033333333', Prospect::query()->where('name', 'فرن الحاج')->value('phone'));
    }

    public function test_a_booked_visit_is_checked_in_and_reported_from_the_phone(): void
    {
        Storage::fake('local');
        $caller = $this->admin('sales', 'Mona Caller');
        $field = $this->admin('field_sales', 'Omar Field');
        $place = $this->place(['phone' => '01044444444']);

        $this->actingAs($caller, 'admin')->post($this->adminUrl("/crm/prospects/{$place->id}/book"), [
            'field_id' => $field->id, 'scheduled_at' => local_at()->addHours(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame(['appointment', $field->id], [$place->fresh()->stage, $place->fresh()->field_id]);

        $this->actingAs($field, 'admin');
        $this->get($this->adminUrl('/crm/visits'))->assertOk()->assertSee($place->name);
        // about 550 m north of the pin
        $this->post($this->adminUrl("/crm/visits/{$appointment->id}/check-in"), ['lat' => 30.7915, 'lng' => 31.0004])->assertRedirect();
        $this->assertEqualsWithDelta(556, $appointment->fresh()->check_in_distance, 10);
        $this->assertFalse($appointment->fresh()->checkedInNear());

        $this->post($this->adminUrl("/crm/visits/{$appointment->id}/report"), [
            'outcome' => 'interested', 'answers' => ['branches' => '2', 'delivery' => 'yes', 'current_system' => 'ورق'], 'next_step' => 'نبعت العرض',
            'photos' => [UploadedFile::fake()->image('menu.jpg')],
        ])->assertRedirect(route('admin.crm.visits'));

        $appointment->refresh();
        $place->refresh();
        $this->assertSame(['done', 'interested'], [$appointment->status, $appointment->outcome]);
        Storage::disk('local')->assertExists($appointment->photos[0]);
        $this->assertSame(['visited', 2, true, 'ورق'], [$place->stage, $place->branches, $place->has_delivery, $place->current_system]);
        $this->get($this->adminUrl("/crm/appointments/{$appointment->id}/photos/0"))->assertOk();
        // another rep cannot report it or see its photo
        $this->actingAs($this->admin('field_sales', 'Ali Field'), 'admin')
            ->get($this->adminUrl("/crm/appointments/{$appointment->id}/photos/0"))->assertNotFound();
    }

    public function test_targets_progress_and_the_pay_estimate_follow_what_was_logged(): void
    {
        $owner = $this->admin('owner', 'Owner');
        $caller = $this->admin('sales', 'Mona Caller');
        $field = $this->admin('field_sales', 'Omar Field');
        $place = $this->place();
        foreach (['done', 'scheduled'] as $status) {
            $place->appointments()->create(['set_by' => $caller->id, 'field_id' => $field->id, 'scheduled_at' => now(), 'status' => $status,
                'reported_at' => $status === 'done' ? now() : null]);
        }
        $month = local_at()->format('Y-m');

        $card = app(TeamStats::class)->card($caller, $month);
        $this->assertSame(40, $card['targets']['appointments']); // the first month of the ramp
        $this->assertSame(5, $card['progress']['appointments']); // 2 of 40
        $this->assertContains(SalesPlan::number('per_visited_appointment'), array_column($card['pay']['lines'], 1));
        $this->assertSame(SalesPlan::number('base.caller') + SalesPlan::number('per_visited_appointment') + SalesPlan::number('phone.caller'), $card['pay']['total']);
        $fieldCard = app(TeamStats::class)->card($field, $month);
        $this->assertSame(1, $fieldCard['month']['visits']);
        $this->assertContains((float) SalesPlan::number('allowance.tanta'), array_column($fieldCard['pay']['lines'], 1));

        $this->actingAs($owner, 'admin')->post($this->adminUrl("/crm/team/{$caller->id}/targets"), [
            'month' => $month, 'calls_per_day' => 40, 'appointments' => 2, 'visits' => 0, 'places' => 0, 'trials' => 5, 'paid' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, SalesTarget::query()->count());
        $card = app(TeamStats::class)->card($caller, $month);
        $this->assertSame(100, $card['progress']['appointments']);
        $this->get($this->adminUrl('/crm/team'))->assertOk()->assertSee('Mona Caller')->assertSee('Omar Field');

        // the owner changes the pay plan; the playbook prints the new number
        $this->post($this->adminUrl('/crm/team/plan'), collect(SalesPlan::EDITABLE)->keys()
            ->mapWithKeys(fn ($path) => [str_replace('.', '__', $path) => data_get(SalesPlan::DEFAULTS, $path)])->put('base__caller', 2750)->all())
            ->assertSessionHasNoErrors();
        $this->assertSame(2750.0, SalesPlan::number('base.caller'));
        $this->get($this->adminUrl('/crm/playbook'))->assertOk()->assertSee('2,750')->assertSee('خطة فريق المبيعات');
    }
}
