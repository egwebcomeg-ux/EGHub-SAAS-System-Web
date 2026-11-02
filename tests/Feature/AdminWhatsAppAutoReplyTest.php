<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Notifications\InboxNeedsAnswer;
use App\Services\WhatsApp\PlatformAutoReply;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The automatic answer on the EgHub number: off until switched on, answers from the assistant, and stays quiet wherever
 * the team has the conversation.
 */
class AdminWhatsAppAutoReplyTest extends TenantTestCase
{
    private const SECRET = 'platform-app-secret';

    private const PHONE_ID = '999000999';

    private const FROM = '201012345678';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.app_secret' => self::SECRET, 'whatsapp.meta.phone_id' => self::PHONE_ID, 'whatsapp.meta.token' => 'meta-access',
            'assistant.provider' => 'anthropic', 'assistant.key' => 'test-key',
        ]);
        PlatformSetting::put(PlatformAutoReply::SETTING, '1');
    }

    private function fakeModel(string $text): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid()]]]),
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function webhook(array $message): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [$message + ['id' => 'wamid.'.uniqid(), 'from' => self::FROM]],
        ]]]]]]);

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    /** @return array<string, mixed> */
    private function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => $body]];
    }

    /** Someone who already got the welcome, so only the answer under test goes out. */
    private function welcomed(): PlatformConversation
    {
        $conversation = PlatformConversation::factory()->create(['phone' => self::FROM, 'last_inbound_at' => now()->subHours(2)]);
        $conversation->messages()->create(['direction' => 'out', 'body' => 'الترحيب', 'status' => 'read', 'created_at' => now()->subDays(2)]);

        return $conversation;
    }

    private function sentToMeta(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'graph.facebook.com'))->count();
    }

    private function askedTheModel(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'api.anthropic.com'))->count();
    }

    public function test_off_by_default_nothing_is_answered(): void
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, null);
        $this->fakeModel('الباقة بكام');

        $this->webhook($this->text('الاسعار؟'))->assertOk();

        $this->assertSame(0, $this->askedTheModel());
        $this->assertSame(0, $this->sentToMeta());
        $this->assertSame(1, PlatformConversation::query()->sole()->unread);
    }

    public function test_it_answers_with_the_channel_rules_and_the_answer_shows_as_automatic(): void
    {
        $this->fakeModel('الكاملة بسعرها من الموقع. كلمنا على https://wa.me/201000000000');
        $this->welcomed();

        $this->webhook($this->text('الاسعار؟'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system'], JSON_UNESCAPED_UNICODE), 'CHANNEL:'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.self::PHONE_ID.'/messages') && $request['to'] === self::FROM && ! str_contains($request['text']['body'], 'wa.me'));

        $conversation = PlatformConversation::query()->sole();
        $row = $conversation->messages()->where('direction', 'out')->latest('id')->first();
        $this->assertNull($row->admin_id);
        $this->assertSame('sent', $row->status);
        $this->assertFalse($conversation->botPaused());

        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/inbox/'.$conversation->id))->assertOk()->assertSee(__('Automatic answer'));
    }

    public function test_asking_for_a_person_hands_over_and_says_so_only_once(): void
    {
        $this->fakeModel('حد من الفريق هيرد عليك هنا. [[HUMAN]]');
        $this->welcomed();

        $this->webhook($this->text('عايز اكلم موظف'))->assertOk();

        $conversation = PlatformConversation::query()->sole();
        $this->assertTrue($conversation->waitingForTeam());
        $this->assertSame(1, $this->sentToMeta());
        $this->assertSame(1, $conversation->messages()->where('direction', 'note')->count());
        $this->assertStringNotContainsString('[[HUMAN]]', (string) $conversation->messages()->where('direction', 'out')->latest('id')->first()->body);

        $this->webhook($this->text('فين الرد'))->assertOk();
        $this->assertSame(1, $this->sentToMeta());
        $this->assertSame(1, $conversation->messages()->where('direction', 'note')->count());
        $this->assertSame(2, $conversation->refresh()->unread);
    }

    public function test_while_waiting_for_the_team_questions_are_answered_until_a_person_answers(): void
    {
        $model = fn (string $text) => ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()->push($model('حد من الفريق هيرد عليك هنا. [[HUMAN]]'))->push($model('الشاشة في الباقة الكاملة')),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid()]]]),
        ]);
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $conversation = $this->welcomed();
        $this->webhook($this->text('عايز اكلم موظف'))->assertOk();
        $this->assertSame($admin->id, $conversation->refresh()->assigned_to);

        $this->webhook($this->text('شاشة'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system']), 'already been told'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && ($request['text']['body'] ?? '') === 'الشاشة في الباقة الكاملة');
        $this->assertSame($admin->id, $conversation->refresh()->assigned_to);

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'أهلا'])->assertSessionHasNoErrors();
        $this->assertFalse($conversation->refresh()->waitingForTeam());
        $asked = $this->askedTheModel();
        $this->webhook($this->text('سؤال تاني'))->assertOk();
        $this->assertSame($asked, $this->askedTheModel());
    }

    public function test_assigning_by_hand_while_waiting_quiets_it(): void
    {
        $this->fakeModel('رد');
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $conversation = $this->welcomed();
        $conversation->forceFill(['waiting_for_team_since' => now()])->save();

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/assign"), ['assigned_to' => $admin->id])->assertRedirect();
        $this->webhook($this->text('سؤال'))->assertOk();

        $this->assertSame(0, $this->askedTheModel());
    }

    public function test_a_team_answer_or_an_assignment_quiets_it_and_the_team_can_give_it_back(): void
    {
        $this->fakeModel('رد آلي');
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $conversation = PlatformConversation::factory()->create(['phone' => self::FROM]);

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'أهلا'])->assertSessionHasNoErrors();
        $this->assertTrue($conversation->refresh()->botPaused());
        $this->webhook($this->text('تمام'))->assertOk();
        $this->assertSame(0, $this->askedTheModel());

        $this->post($this->adminUrl("/inbox/{$conversation->id}/resume-bot"))->assertRedirect();
        $this->assertFalse($conversation->refresh()->botPaused());
        $this->webhook($this->text('الاسعار'))->assertOk();
        $this->assertSame(1, $this->askedTheModel());

        $conversation->refresh()->update(['assigned_to' => $admin->id]);
        $this->webhook($this->text('سؤال تاني'))->assertOk();
        $this->assertSame(1, $this->askedTheModel());
    }

    public function test_media_gets_the_fixed_line_without_the_model_and_a_reaction_gets_nothing(): void
    {
        $this->fakeModel('رد');

        $this->webhook(['type' => 'reaction', 'reaction' => ['emoji' => 'x']])->assertOk();
        $this->assertSame(0, $this->sentToMeta());

        $this->webhook(['type' => 'audio', 'audio' => ['id' => '1']])->assertOk();
        $this->assertSame(0, $this->askedTheModel());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && ($request['text']['body'] ?? '') === PlatformAutoReply::HANDOVER_TEXT);
        $this->assertTrue(PlatformConversation::query()->sole()->waitingForTeam());
    }

    public function test_when_the_model_is_down_the_person_is_told_the_team_will_answer(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'down'], 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]]),
        ]);
        $this->welcomed();

        $this->webhook($this->text('الاسعار'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['text']['body'] === PlatformAutoReply::HANDOVER_TEXT);
        $this->assertTrue(PlatformConversation::query()->sole()->waitingForTeam());
    }

    public function test_after_the_daily_limit_the_team_takes_over_without_the_model(): void
    {
        $this->fakeModel('رد');
        $conversation = PlatformConversation::factory()->create(['phone' => self::FROM]);
        foreach (range(1, PlatformAutoReply::DAILY_LIMIT) as $i) {
            $conversation->messages()->create(['direction' => 'out', 'body' => "رد $i", 'status' => 'sent']);
        }

        $this->webhook($this->text('كمان سؤال'))->assertOk();

        $this->assertSame(0, $this->askedTheModel());
        $this->assertTrue($conversation->refresh()->waitingForTeam());
    }

    public function test_the_first_message_gets_the_welcome_with_buttons_and_a_hello_needs_nothing_more(): void
    {
        $this->fakeModel('رد');

        $this->webhook($this->text('السلام عليكم'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['type'] === 'interactive' && count($request['interactive']['action']['buttons']) === 3);
        $this->assertSame(0, $this->askedTheModel());

        $this->webhook($this->text('الاسعار'))->assertOk();
        $this->assertSame(1, $this->askedTheModel());
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['type'] === 'interactive')->count());
    }

    public function test_a_long_islamic_greeting_gets_the_buttons_only(): void
    {
        $this->fakeModel('رد');

        $this->webhook($this->text('السلام عليكم ورحمة الله وبركاته'))->assertOk();

        $this->assertSame(0, $this->askedTheModel());
        $this->assertSame(1, $this->sentToMeta());
        $this->assertFalse(PlatformConversation::query()->sole()->botPaused());
    }

    public function test_a_later_hello_gets_the_buttons_and_does_not_answer_an_older_unanswered_question(): void
    {
        $this->fakeModel('الأسعار كذا');
        $conversation = $this->welcomed();
        $conversation->messages()->create(['direction' => 'in', 'type' => 'interactive', 'body' => 'الأسعار والباقات']);

        $this->webhook($this->text('السلام عليكم'))->assertOk();

        $this->assertSame(0, $this->askedTheModel());
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['type'] === 'interactive')->count());
    }

    public function test_a_real_first_question_gets_the_welcome_and_the_answer(): void
    {
        $this->fakeModel('الإجابة');

        $this->webhook($this->text('الاسعار كام'))->assertOk();

        $this->assertSame(1, $this->askedTheModel());
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api.anthropic.com') || end($request->data()['messages'])['role'] === 'user');
        $this->assertSame(2, $this->sentToMeta());
    }

    public function test_a_tap_on_a_welcome_button_is_answered_and_the_team_button_hands_over_to_the_least_busy_member(): void
    {
        $this->fakeModel('حد من الفريق هيرد عليك هنا.');
        $busy = PlatformAdmin::query()->create(['name' => 'Busy', 'email' => 'busy@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $free = PlatformAdmin::query()->create(['name' => 'Free', 'email' => 'free@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        PlatformConversation::factory()->create(['assigned_to' => $busy->id]);
        $conversation = PlatformConversation::factory()->create(['phone' => self::FROM]);
        $conversation->messages()->create(['direction' => 'out', 'body' => 'الترحيب', 'status' => 'read']);

        $this->webhook(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'inbox:team', 'title' => 'أكلم حد من الفريق']]])->assertOk();

        $this->assertSame('أكلم حد من الفريق', $conversation->messages()->where('direction', 'in')->sole()->body);
        $this->assertSame($free->id, $conversation->refresh()->assigned_to);
    }

    public function test_the_teams_ready_answers_reach_the_assistant(): void
    {
        $this->fakeModel('رد');
        PlatformCannedReply::query()->create(['title' => 'مواعيد العمل', 'body' => 'من ١٠ الصبح']);

        $this->webhook($this->text('بتفتحوا امتى'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system'], JSON_UNESCAPED_UNICODE), 'من ١٠ الصبح'));
    }

    public function test_start_sends_the_template_to_a_new_customer_once(): void
    {
        $this->fakeModel('رد');
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/inbox/start'), ['phone' => '01012345678', 'name' => 'منى'])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['to'] === self::FROM && $request['type'] === 'template' && $request['template']['name'] === 'egmenu_welcome' && $request['template']['components'][0]['parameters'][0]['text'] === 'منى');
        $conversation = PlatformConversation::query()->sole();
        $this->assertStringContainsString('منى', (string) $conversation->messages()->sole()->body);
        $this->assertFalse($conversation->canReply());

        $this->post($this->adminUrl('/inbox/start'), ['phone' => '01012345678', 'name' => 'منى'])->assertRedirect();
        $this->assertSame(1, $this->sentToMeta());
    }

    public function test_start_needs_inbox_manage_and_a_valid_mobile(): void
    {
        Http::fake();
        $field = PlatformAdmin::query()->create(['name' => 'Field', 'email' => 'field@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'field_sales']);
        $this->actingAs($field, 'admin')->post($this->adminUrl('/inbox/start'), ['phone' => '01012345678', 'name' => 'منى'])->assertForbidden();

        $support = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $this->actingAs($support, 'admin')->post($this->adminUrl('/inbox/start'), ['phone' => 'abc', 'name' => 'منى'])->assertSessionHasErrors('phone');
        Http::assertNothingSent();
    }

    public function test_the_team_gets_one_email_on_hand_over_and_only_the_follower_after_it(): void
    {
        Notification::fake();
        $this->fakeModel('حد من الفريق هيرد عليك هنا. [[HUMAN]]');
        $first = PlatformAdmin::query()->create(['name' => 'First', 'email' => 'first@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $second = PlatformAdmin::query()->create(['name' => 'Second', 'email' => 'second@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $conversation = $this->welcomed();

        $this->webhook($this->text('عايز اكلم موظف'))->assertOk();
        $this->webhook($this->text('فين الرد'))->assertOk();

        $this->assertSame($first->id, $conversation->refresh()->assigned_to);
        Notification::assertSentToTimes($first, InboxNeedsAnswer::class, 1);
        Notification::assertNotSentTo($second, InboxNeedsAnswer::class);
    }

    public function test_with_the_automatic_answer_off_the_whole_team_is_told_but_not_of_a_reaction(): void
    {
        Notification::fake();
        Http::fake();
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $field = PlatformAdmin::query()->create(['name' => 'Field', 'email' => 'field@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'field_sales']);

        $this->webhook(['type' => 'reaction', 'reaction' => ['emoji' => 'x']])->assertOk();
        Notification::assertNothingSent();

        $this->webhook($this->text('الاسعار'))->assertOk();
        Notification::assertSentTo($admin, InboxNeedsAnswer::class);
        Notification::assertNotSentTo($field, InboxNeedsAnswer::class);
    }

    public function test_the_switch_needs_inbox_manage(): void
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');
        $field = PlatformAdmin::query()->create(['name' => 'Field', 'email' => 'field@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'field_sales']);
        $this->actingAs($field, 'admin')->post($this->adminUrl('/inbox/auto-reply'), ['enabled' => 1])->assertForbidden();
        $this->assertSame('0', PlatformSetting::get(PlatformAutoReply::SETTING));

        $sales = PlatformAdmin::query()->create(['name' => 'Mona', 'email' => 'mona@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'sales']);
        $this->actingAs($sales, 'admin')->post($this->adminUrl('/inbox/auto-reply'), ['enabled' => 1])->assertForbidden();
        $this->assertSame('0', PlatformSetting::get(PlatformAutoReply::SETTING));

        $manager = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'manager']);
        $this->actingAs($manager, 'admin')->post($this->adminUrl('/inbox/auto-reply'), ['enabled' => 1])->assertRedirect();
        $this->assertSame('1', PlatformSetting::get(PlatformAutoReply::SETTING));
    }
}
