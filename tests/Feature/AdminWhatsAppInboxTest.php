<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * «محادثات واتساب»: what people write to the EgHub number is kept for the team (once, without sign-in codes and never a
 * restaurant's own number), answers go out only inside the 24-hour window, and only inbox.manage opens it.
 */
class AdminWhatsAppInboxTest extends TenantTestCase
{
    private const SECRET = 'platform-app-secret';

    private const PHONE_ID = '999000999';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.app_secret' => self::SECRET, 'whatsapp.meta.phone_id' => self::PHONE_ID, 'whatsapp.meta.token' => 'tok']);
    }

    private function admin(string $role = 'support'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => "Sara $role", 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** @param  array<string, mixed>  $value */
    private function webhook(array $value, string $phoneId = self::PHONE_ID): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => $value + [
            'metadata' => ['phone_number_id' => $phoneId],
        ]]]]]]);

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    /** @return array<string, mixed> */
    private function text(string $body, string $id = 'wamid.in1', string $from = '201012345678'): array
    {
        return ['contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Mona Cafe']]], 'messages' => [['id' => $id, 'from' => $from, 'type' => 'text', 'text' => ['body' => $body]]]];
    }

    public function test_a_message_to_the_eghub_number_is_kept_once_and_shows_unread_for_the_team(): void
    {
        $this->webhook($this->text('عايز اعرف الاسعار'))->assertOk();
        $this->webhook($this->text('عايز اعرف الاسعار'))->assertOk();

        $conversation = PlatformConversation::query()->sole();
        $this->assertSame('201012345678', $conversation->phone);
        $this->assertSame('Mona Cafe', $conversation->name);
        $this->assertSame(1, $conversation->unread);
        $this->assertSame(1, $conversation->messages()->count());

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/inbox'))->assertOk()->assertSee('Mona Cafe')->assertSee('data-inbox-unread', false);
        $this->get($this->adminUrl('/inbox/'.$conversation->id))->assertOk()->assertSee('عايز اعرف الاسعار');
        $this->assertSame(0, $conversation->refresh()->unread);
    }

    public function test_a_closed_conversation_reopens_and_media_shows_as_a_label(): void
    {
        $conversation = PlatformConversation::factory()->create(['phone' => '201012345678', 'status' => 'closed']);

        $this->webhook(['messages' => [['id' => 'wamid.img', 'from' => '201012345678', 'type' => 'image', 'image' => ['caption' => 'المنيو']]]])->assertOk();

        $this->assertSame('open', $conversation->refresh()->status);
        $this->assertSame('[صورة] المنيو', $conversation->messages()->sole()->body);
    }

    public function test_a_message_for_another_number_is_never_kept_here(): void
    {
        $this->webhook($this->text('سر'), '555555')->assertOk();

        $this->assertSame(0, PlatformConversation::query()->count());
    }

    public function test_a_connected_restaurant_number_never_reaches_the_team(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->as($tenant, fn () => WhatsAppConnection::query()->create([
            'waba_id' => '111', 'phone_number_id' => '222', 'access_token' => 'tok', 'webhook_key' => random_token(16), 'verify_token' => random_token(20), 'status' => 'connected',
        ]));

        $this->webhook($this->text('رسالة لعميل المطعم'), '222')->assertOk();

        $this->assertSame(0, PlatformConversation::query()->count());
    }

    public function test_the_owners_phone_confirmation_code_is_not_kept(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $owner->forceFill(['phone' => '01012345678', 'phone_verified_at' => null, 'phone_verification_code' => 'AB2CD3'])->save();

        $this->webhook($this->text('EGMENU AB2CD3'))->assertOk();

        $this->assertNotNull($owner->refresh()->phone_verified_at);
        $this->assertSame(0, PlatformConversation::query()->count());
    }

    public function test_the_answer_goes_out_inside_the_window_and_its_status_follows(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);
        $conversation = PlatformConversation::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'الباقات من هنا'])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.self::PHONE_ID.'/messages') && $request['to'] === $conversation->phone && $request['text']['body'] === 'الباقات من هنا');
        $row = $conversation->messages()->sole();
        $this->assertSame(['out', 'sent', $admin->id], [$row->direction, $row->status, $row->admin_id]);

        $this->webhook(['statuses' => [['id' => 'wamid.out1', 'status' => 'read']]])->assertOk();
        $this->webhook(['statuses' => [['id' => 'wamid.out1', 'status' => 'delivered']]])->assertOk();
        $this->assertSame('read', $row->refresh()->status);
    }

    public function test_after_24_hours_nothing_is_sent(): void
    {
        Http::fake();
        $conversation = PlatformConversation::factory()->create(['last_inbound_at' => now()->subHours(25)]);

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl("/inbox/{$conversation->id}"))->assertOk()->assertSee('data-inbox-window-closed', false)->assertDontSee('data-inbox-reply', false);
        $this->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'متأخر'])->assertSessionHasErrors('body');

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_after_24_hours_a_follow_up_template_reaches_them_once_a_day(): void
    {
        config(['whatsapp.business_account_id' => '555']);
        Http::fake(['graph.facebook.com/*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(['data' => [['name' => 'egmenu_welcome', 'status' => 'APPROVED'], ['name' => 'eghub_restaurant_welcome', 'status' => 'APPROVED']]])
            : Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $this->restaurant('cafe-a');
        $owner = PlatformConversation::factory()->create(['last_inbound_at' => now()->subHours(25), 'name' => 'Amira', 'tenant_id' => 'cafe-a']);
        $lead = PlatformConversation::factory()->create(['last_inbound_at' => now()->subHours(25), 'name' => 'Mona']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get($this->adminUrl("/inbox/{$owner->id}"))->assertSee('data-inbox-follow-up', false);
        $this->post($this->adminUrl("/inbox/{$owner->id}/follow-up"))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->adminUrl("/inbox/{$lead->id}/follow-up"))->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => ($request['to'] ?? null) === $owner->phone && $request['template']['name'] === 'eghub_restaurant_welcome');
        Http::assertSent(fn ($request) => ($request['to'] ?? null) === $lead->phone && $request['template']['name'] === 'egmenu_welcome');
        $this->assertSame($admin->id, $owner->messages()->sole()->admin_id);

        // not twice in a day, and never while free text is allowed
        $this->post($this->adminUrl("/inbox/{$owner->id}/follow-up"))->assertSessionHasErrors('body');
        $open = PlatformConversation::factory()->create();
        $this->post($this->adminUrl("/inbox/{$open->id}/follow-up"))->assertRedirect();
        $this->assertSame([1, 1, 0], [$owner->messages()->count(), $lead->messages()->count(), $open->messages()->count()]);
    }

    public function test_the_pulse_tells_the_admin_pages_of_a_new_message_only_with_inbox_manage(): void
    {
        $this->webhook($this->text('عايز اعرف الاسعار'))->assertOk();
        $conversation = PlatformConversation::query()->sole();

        $this->actingAs($this->admin(), 'admin')->getJson($this->adminUrl('/inbox/pulse'))->assertOk()
            ->assertJsonPath('unread', 1)->assertJsonPath('latest.name', 'Mona Cafe')->assertJsonPath('latest.body', 'عايز اعرف الاسعار')
            ->assertJsonPath('latest.id', $conversation->messages()->sole()->id);
        $this->get($this->adminUrl('/'))->assertSee('data-inbox-pulse=', false);

        $this->actingAs($this->admin('finance'), 'admin')->getJson($this->adminUrl('/inbox/pulse'))->assertForbidden();
    }

    public function test_when_meta_refuses_the_team_sees_why_and_the_text_stays(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'session expired', 'code' => 190]], 401)]);
        $conversation = PlatformConversation::factory()->create();

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'رد'])->assertSessionHasErrors('body');

        $row = $conversation->messages()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('session expired', $row->error);
    }

    public function test_what_a_person_writes_is_escaped(): void
    {
        $this->webhook($this->text('<script>alert(1)</script>'));
        $conversation = PlatformConversation::query()->sole();

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/inbox/'.$conversation->id))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_notes_stay_inside_assignment_only_takes_the_team_and_closing_works(): void
    {
        Http::fake();
        $conversation = PlatformConversation::factory()->create();
        $admin = $this->admin();
        $field = $this->admin('field_sales');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/note"), ['body' => 'كلمته امبارح'])->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->assertSame('note', $conversation->messages()->sole()->direction);

        $this->post($this->adminUrl("/inbox/{$conversation->id}/assign"), ['assigned_to' => $field->id])->assertSessionHasErrors('assigned_to');
        $this->post($this->adminUrl("/inbox/{$conversation->id}/assign"), ['assigned_to' => $admin->id])->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $conversation->refresh()->assigned_to);
        $this->get($this->adminUrl('/inbox?status=mine'))->assertSee('data-inbox-row="'.$conversation->id.'"', false);

        $this->post($this->adminUrl("/inbox/{$conversation->id}/status"), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->get($this->adminUrl('/inbox'))->assertDontSee('data-inbox-row="'.$conversation->id.'"', false);
        $this->get($this->adminUrl('/inbox?status=closed'))->assertSee('data-inbox-row="'.$conversation->id.'"', false);
    }

    public function test_ready_answers_are_saved_and_offered_without_sending_anything(): void
    {
        Http::fake();
        $conversation = PlatformConversation::factory()->create();

        $this->actingAs($this->admin('manager'), 'admin')->post($this->adminUrl('/inbox/canned'), ['title' => 'الأسعار', 'body' => 'الباقات على eghub.app'])->assertSessionHasNoErrors();
        $this->get($this->adminUrl("/inbox/{$conversation->id}"))->assertSee('الباقات على eghub.app');
        Http::assertNothingSent();

        $this->post($this->adminUrl('/inbox/canned/'.PlatformCannedReply::query()->sole()->id.'/delete'))->assertRedirect();
        $this->assertSame(0, PlatformCannedReply::query()->count());
    }

    public function test_only_inbox_manage_opens_it_and_guests_are_sent_to_login(): void
    {
        $conversation = PlatformConversation::factory()->create();

        $this->get($this->adminUrl('/inbox'))->assertRedirect();
        $this->actingAs($this->admin('field_sales'), 'admin')->get($this->adminUrl('/inbox'))->assertForbidden();
        $this->get($this->adminUrl("/inbox/{$conversation->id}"))->assertForbidden();
        $this->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'x'])->assertForbidden();
        $this->assertSame(0, PlatformConversationMessage::query()->count());
    }
}
