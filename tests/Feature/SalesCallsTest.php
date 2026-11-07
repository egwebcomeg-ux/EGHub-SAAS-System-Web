<?php

namespace Tests\Feature;

use App\Models\CallDevice;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\SalesCall;
use App\Services\Push\FcmClient;
use Tests\TenantTestCase;

/** The sales phone: its token, who a number belongs to, the call log it sends, the summary and who sees what. */
class SalesCallsTest extends TenantTestCase
{
    private function admin(string $role, string $name): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@egweb.test', 'password' => 'Secret-pass-123', 'role' => $role,
        ]);
    }

    /** @return array{0: PlatformAdmin, 1: array<string, string>} */
    private function linkedPhone(string $role = 'sales', string $name = 'Mona Caller'): array
    {
        config(['eghub.admin_require_2fa' => false]);
        $admin = $this->admin($role, $name);
        $token = $this->actingAs($admin, 'admin')->postJson($this->adminUrl('/crm/call-devices'), ['name' => 'Samsung A24'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return [$admin, ['Authorization' => 'Bearer '.$token]];
    }

    private function row(string $id, string $phone, string $direction = 'outgoing', int $duration = 75, ?int $startedAt = null): array
    {
        return ['id' => $id, 'phone' => $phone, 'direction' => $direction, 'started_at' => $startedAt ?? now()->getTimestampMs(), 'duration' => $duration];
    }

    public function test_a_phone_needs_a_live_token_of_an_admin_who_handles_sales(): void
    {
        $url = $this->adminUrl('/crm/device/directory');
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        [$admin, $headers] = $this->linkedPhone();
        $this->assertSame('Samsung A24', CallDevice::query()->sole()->name);
        $this->assertNotSame(substr($headers['Authorization'], 7), CallDevice::query()->sole()->token_hash);
        $this->getJson($url, $headers)->assertOk();

        $admin->forceFill(['is_active' => false])->save();
        $this->getJson($url, $headers)->assertUnauthorized();
    }

    public function test_call_on_the_computer_places_the_call_on_the_admins_own_phone_only(): void
    {
        [$mona, $headers] = $this->linkedPhone();
        [$ali, $aliHeaders] = $this->linkedPhone('sales', 'Ali Caller');
        Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);
        $this->postJson($this->adminUrl('/crm/device/push-token'), ['token' => 'ali-phone'], $aliHeaders)->assertOk();

        // no phone that can dial yet: the page keeps the plain tel: link and the request is refused
        $this->actingAs($mona, 'admin')->get($this->adminUrl('/crm/calls'))->assertDontSee('data-dial-url', false);
        $this->postJson($this->adminUrl('/crm/calls/dial'), ['phone' => '01066666666'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->postJson($this->adminUrl('/crm/device/push-token'), ['token' => 'mona-phone'], $headers)->assertOk();
        $this->mock(FcmClient::class, fn ($fcm) => $fcm->shouldReceive('send')->once()
            ->with('mona-phone', '', '', ['type' => 'sales_dial', 'number' => '01066666666', 'name' => 'مطعم الأصيل'], true)
            ->andReturn(FcmClient::SENT));

        $this->actingAs($mona, 'admin')->get($this->adminUrl('/crm/calls'))->assertSee('data-dial-url', false);
        $this->postJson($this->adminUrl('/crm/calls/dial'), ['phone' => '010 6666 6666'])->assertOk()->assertJsonPath('device', 'Samsung A24');
        // the computer shows who is being called at once
        $this->getJson($this->adminUrl('/crm/calls/current'))->assertJsonPath('call.name', 'مطعم الأصيل')->assertJsonPath('call.direction', 'outgoing');
        $this->postJson($this->adminUrl('/crm/calls/dial'), ['phone' => 'javascript:alert(1)'])->assertUnprocessable();
    }

    public function test_the_card_while_it_rings_knows_a_restaurant_a_place_and_a_new_number_and_reaches_the_computer(): void
    {
        [$admin, $headers] = $this->linkedPhone();
        $tenant = $this->restaurant('cafe-a');
        $this->owner($tenant)->forceFill(['phone' => '01011111111'])->save();
        Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);
        $ring = fn (string $phone) => $this->postJson($this->adminUrl('/crm/device/ringing'), ['phone' => $phone, 'direction' => 'incoming'], $headers)->assertOk()->json('card');

        $this->assertSame([true, $tenant->name], [$ring('+20 101 111 1111')['known'], $ring('01011111111')['name']]);
        $this->assertSame('مطعم الأصيل', $ring('01066666666')['name']);
        $this->assertFalse($ring('0403331234')['known']);

        $this->actingAs($admin, 'admin');
        $this->getJson($this->adminUrl('/crm/calls/current'))->assertOk()->assertJsonPath('call.phone', '0403331234');
        $other = $this->admin('sales', 'Ali Caller');
        $this->actingAs($other, 'admin');
        $this->getJson($this->adminUrl('/crm/calls/current'))->assertOk()->assertJsonPath('call', null);
    }

    public function test_the_call_log_is_stored_once_tied_to_its_number_and_a_talked_call_closes_the_missed_one(): void
    {
        [$admin, $headers] = $this->linkedPhone();
        $place = Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);
        $url = $this->adminUrl('/crm/device/calls');
        $earlier = now()->subHour()->getTimestampMs();

        $this->postJson($url, ['calls' => [$this->row('a', '01066666666', 'missed', 0, $earlier)]], $headers)->assertOk()->assertJsonPath('summary', null);
        $this->assertSame(1, SalesCall::query()->missedNotReturned()->count());

        $summary = $this->postJson($url, ['calls' => [$this->row('b', '+201066666666')]], $headers)->assertOk()->json('summary');
        $this->postJson($url, ['calls' => [$this->row('b', '+201066666666')]], $headers)->assertOk()->assertJsonPath('summary', null);

        $this->assertSame(2, SalesCall::query()->count());
        $this->assertSame(0, SalesCall::query()->missedNotReturned()->count());
        $call = SalesCall::query()->where('external_id', 'b')->sole();
        $this->assertSame([$admin->id, $place->id, 75, '01066666666'], [$call->admin_id, $call->prospect_id, $call->duration_seconds, $call->phone]);
        $this->assertStringContainsString("/crm/calls/{$call->id}", $summary['url']);
        $this->assertSame($admin->id, $place->refresh()->caller_id);
        $this->assertSame('new', $place->stage);
    }

    public function test_the_summary_goes_on_the_timeline_once_and_moves_a_new_place_and_lead(): void
    {
        [$admin, $headers] = $this->linkedPhone();
        $tenant = $this->restaurant('cafe-a');
        $this->owner($tenant)->forceFill(['phone' => '01011111111'])->save();
        $place = Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);
        $this->postJson($this->adminUrl('/crm/device/calls'), ['calls' => [$this->row('p', '01066666666'), $this->row('l', '01011111111')]], $headers)->assertOk();
        $this->actingAs($admin, 'admin');
        $placeCall = SalesCall::query()->where('external_id', 'p')->sole();
        $leadCall = SalesCall::query()->where('external_id', 'l')->sole();

        $this->post($this->adminUrl("/crm/calls/{$placeCall->id}"), ['outcome' => 'answered', 'notes' => 'عايز منيو', 'next_follow_up_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
        $this->post($this->adminUrl("/crm/calls/{$placeCall->id}"), ['outcome' => 'answered', 'notes' => 'عايز منيو كامل'])->assertRedirect();
        $this->post($this->adminUrl("/crm/calls/{$leadCall->id}"), ['outcome' => 'answered', 'notes' => 'هيشترك'])->assertRedirect();

        $place->refresh();
        $this->assertSame('contacted', $place->stage);
        $this->assertNotNull($place->next_follow_up_at);
        $this->assertSame(1, $place->events()->where('type', 'call')->count());
        $this->assertSame('عايز منيو كامل', $placeCall->refresh()->notes);
        $lead = Lead::query()->where('tenant_id', 'cafe-a')->sole();
        $this->assertSame('contacted', $lead->stage);
        $this->assertSame('هيشترك', $lead->events()->where('type', 'call')->sole()->notes);
    }

    public function test_a_sales_person_sees_only_their_calls_and_a_field_rep_none(): void
    {
        [$mona, $headers] = $this->linkedPhone();
        $this->postJson($this->adminUrl('/crm/device/calls'), ['calls' => [$this->row('m', '0403331234')]], $headers)->assertOk();
        $call = SalesCall::query()->sole();
        config(['eghub.admin_require_2fa' => false]);

        $this->actingAs($mona, 'admin');
        $this->get($this->adminUrl('/crm/calls'))->assertOk()->assertSee('0403331234')
            // the phone app itself is downloaded from here
            ->assertSee('/downloads/eghub-sales.apk', false);
        $this->get($this->adminUrl("/crm/calls/{$call->id}"))->assertOk();

        $this->actingAs($this->admin('sales', 'Ali Caller'), 'admin');
        $this->get($this->adminUrl('/crm/calls'))->assertOk()->assertDontSee('0403331234');
        $this->get($this->adminUrl("/crm/calls/{$call->id}"))->assertNotFound();
        $this->post($this->adminUrl("/crm/calls/{$call->id}"), ['outcome' => 'answered'])->assertNotFound();

        $this->actingAs($this->admin('field_sales', 'Omar Field'), 'admin');
        $this->get($this->adminUrl('/crm/calls'))->assertForbidden();

        $this->actingAs($this->admin('owner', 'The Owner'), 'admin');
        $this->get($this->adminUrl('/crm/calls'))->assertOk()->assertSee('0403331234');
    }

    public function test_a_new_number_becomes_a_place_with_its_calls(): void
    {
        [$mona, $headers] = $this->linkedPhone();
        $this->postJson($this->adminUrl('/crm/device/calls'), ['calls' => [$this->row('n', '01077777777')]], $headers)->assertOk();
        $call = SalesCall::query()->sole();

        $this->actingAs($mona, 'admin')->post($this->adminUrl("/crm/calls/{$call->id}/place"), ['name' => 'كافيه جديد', 'category' => 'cafe'])->assertRedirect();

        $place = Prospect::query()->sole();
        $this->assertSame(['كافيه جديد', '01077777777', $mona->id], [$place->name, $place->phone, $place->caller_id]);
        $this->assertSame($place->id, $call->refresh()->prospect_id);
    }

    public function test_only_the_owner_of_a_phone_unlinks_it(): void
    {
        [$mona] = $this->linkedPhone();
        $device = CallDevice::query()->sole();
        $this->actingAs($this->admin('sales', 'Ali Caller'), 'admin')->post($this->adminUrl("/crm/call-devices/{$device->id}/delete"))->assertNotFound();
        $this->actingAs($mona, 'admin')->post($this->adminUrl("/crm/call-devices/{$device->id}/delete"))->assertRedirect();
        $this->assertSame(0, CallDevice::query()->count());
    }
}
