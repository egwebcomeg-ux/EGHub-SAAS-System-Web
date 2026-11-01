<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\PlatformAutoReply;
use Illuminate\Support\Facades\Http;
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

        $this->webhook($this->text('الاسعار؟'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system'], JSON_UNESCAPED_UNICODE), 'CHANNEL:'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.self::PHONE_ID.'/messages') && $request['to'] === self::FROM && ! str_contains($request['text']['body'], 'wa.me'));

        $conversation = PlatformConversation::query()->sole();
        $row = $conversation->messages()->where('direction', 'out')->sole();
        $this->assertNull($row->admin_id);
        $this->assertSame('sent', $row->status);
        $this->assertFalse($conversation->botPaused());

        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/inbox/'.$conversation->id))->assertOk()->assertSee(__('Automatic answer'));
    }

    public function test_asking_for_a_person_hands_over_and_later_messages_get_no_answer(): void
    {
        $this->fakeModel('حد من الفريق هيرد عليك هنا. [[HUMAN]]');

        $this->webhook($this->text('عايز اكلم موظف'))->assertOk();

        $conversation = PlatformConversation::query()->sole();
        $this->assertTrue($conversation->botPaused());
        $this->assertSame(1, $this->sentToMeta());
        $this->assertSame(1, $conversation->messages()->where('direction', 'note')->count());
        $this->assertStringNotContainsString('[[HUMAN]]', (string) $conversation->messages()->where('direction', 'out')->sole()->body);

        $this->webhook($this->text('فين الرد'))->assertOk();
        $this->assertSame(1, $this->askedTheModel());
        $this->assertSame(1, $this->sentToMeta());
        $this->assertSame(2, $conversation->refresh()->unread);
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
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['text']['body'] === PlatformAutoReply::HANDOVER_TEXT);
        $this->assertTrue(PlatformConversation::query()->sole()->botPaused());
    }

    public function test_when_the_model_is_down_the_person_is_told_the_team_will_answer(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'down'], 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]]),
        ]);

        $this->webhook($this->text('الاسعار'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['text']['body'] === PlatformAutoReply::HANDOVER_TEXT);
        $this->assertTrue(PlatformConversation::query()->sole()->botPaused());
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
        $this->assertTrue($conversation->refresh()->botPaused());
    }

    public function test_the_switch_needs_inbox_manage(): void
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');
        $field = PlatformAdmin::query()->create(['name' => 'Field', 'email' => 'field@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'field_sales']);
        $this->actingAs($field, 'admin')->post($this->adminUrl('/inbox/auto-reply'), ['enabled' => 1])->assertForbidden();
        $this->assertSame('0', PlatformSetting::get(PlatformAutoReply::SETTING));

        $support = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $this->actingAs($support, 'admin')->post($this->adminUrl('/inbox/auto-reply'), ['enabled' => 1])->assertRedirect();
        $this->assertSame('1', PlatformSetting::get(PlatformAutoReply::SETTING));
    }
}
