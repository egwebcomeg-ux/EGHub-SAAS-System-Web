<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\PlatformAutoReply;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** The EgHub page's Messenger and Instagram in the team's inbox: same automatic answer, answered where the person wrote. */
class AdminPageMessagesTest extends TenantTestCase
{
    private const SECRET = 'platform-app-secret';

    private const PAGE = '376000000';

    private const INSTAGRAM = '178000000';

    private const SENDER = '7000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.app_secret' => self::SECRET, 'whatsapp.meta.phone_id' => '999', 'whatsapp.meta.token' => 'wa-token',
            'whatsapp.page' => ['id' => self::PAGE, 'instagram_id' => self::INSTAGRAM, 'token' => 'page-token', 'app_id' => '555'],
            'assistant.provider' => 'anthropic', 'assistant.key' => 'test-key',
        ]);
        PlatformSetting::put(PlatformAutoReply::SETTING, '1');
    }

    private function fakeMeta(string $answer = 'الباقة بسعرها من الموقع'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
            'graph.facebook.com/*' => fn ($request) => $request->method() === 'GET'
                ? Http::response(['first_name' => 'Mona', 'last_name' => 'Ali'])
                : Http::response(['recipient_id' => self::SENDER, 'message_id' => 'm_'.uniqid()]),
        ]);
    }

    /** @param  array<string, mixed>  $event */
    private function webhook(array $event, string $object = 'page', string $account = self::PAGE, ?string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode(['object' => $object, 'entry' => [['id' => $account, 'messaging' => [$event + ['sender' => ['id' => self::SENDER], 'recipient' => ['id' => $account]]]]]]);

        return $this->call('POST', $this->menuUrl('webhooks/meta'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, (string) $secret),
        ], $raw);
    }

    /** @return array<string, mixed> */
    private function message(string $text): array
    {
        return ['message' => ['mid' => 'm_'.uniqid(), 'text' => $text]];
    }

    private function welcomed(string $channel = PlatformConversation::MESSENGER): PlatformConversation
    {
        $conversation = PlatformConversation::factory()->create(['channel' => $channel, 'phone' => self::SENDER]);
        $conversation->messages()->create(['direction' => 'out', 'body' => 'الترحيب', 'status' => 'read']);

        return $conversation;
    }

    /** @return Collection<int, array{0: Request, 1: mixed}> */
    private function sent(): Collection
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/'.self::PAGE.'/messages'));
    }

    public function test_a_first_messenger_question_gets_the_welcome_with_quick_replies_and_the_answer_there(): void
    {
        $this->fakeMeta();

        $this->webhook($this->message('الاسعار كام'))->assertOk();

        $conversation = PlatformConversation::query()->sole();
        $this->assertSame(PlatformConversation::MESSENGER, $conversation->channel);
        $this->assertSame(self::SENDER, $conversation->phone);
        $this->assertSame('Mona Ali', $conversation->name);

        $this->assertDatabaseHas('assistant_messages', ['conversation' => 'fb-'.$conversation->id]);

        $sent = $this->sent()->values();
        $this->assertCount(2, $sent);
        $this->assertSame(self::SENDER, $sent[0][0]['recipient']['id']);
        $this->assertCount(3, $sent[0][0]['message']['quick_replies']);
        $this->assertStringNotContainsString('الرقم', $sent[0][0]['message']['text']);
        $this->assertSame('الباقة بسعرها من الموقع', $sent[1][0]['message']['text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system']), 'Messenger'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/999/messages'));
    }

    public function test_a_long_instagram_answer_goes_in_parts_within_the_limit(): void
    {
        $this->fakeMeta(str_repeat('سعر الباقة من الموقع ', 120));
        $this->welcomed(PlatformConversation::INSTAGRAM);

        $this->webhook($this->message('الاسعار'), 'instagram', self::INSTAGRAM)->assertOk();

        $sent = $this->sent();
        $this->assertGreaterThan(1, $sent->count());
        $sent->each(fn ($call) => $this->assertLessThanOrEqual(1000, strlen($call[0]['message']['text'])));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') && str_contains(json_encode($request['system']), 'Instagram'));
    }

    public function test_the_same_id_on_two_channels_stays_two_conversations(): void
    {
        $this->fakeMeta();
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');

        $this->webhook($this->message('من ماسنجر'))->assertOk();
        $this->webhook($this->message('من إنستجرام'), 'instagram', self::INSTAGRAM)->assertOk();

        $this->assertEqualsCanonicalizing([PlatformConversation::MESSENGER, PlatformConversation::INSTAGRAM], PlatformConversation::query()->pluck('channel')->all());
    }

    public function test_a_payload_without_the_signature_or_for_another_page_is_not_kept(): void
    {
        $this->fakeMeta();

        $this->webhook($this->message('مزور'), secret: 'wrong')->assertForbidden();
        $this->webhook($this->message('صفحة مطعم'), account: '111')->assertOk();

        $this->assertSame(0, PlatformConversation::query()->count());
    }

    public function test_the_same_message_sent_twice_is_kept_once(): void
    {
        $this->fakeMeta();
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');
        $event = $this->message('الاسعار');

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        $this->assertSame(1, PlatformConversation::query()->sole()->messages()->count());
    }

    public function test_our_own_echo_is_ignored_and_an_answer_in_metas_inbox_quiets_the_automatic_answer(): void
    {
        $this->fakeMeta();
        $conversation = $this->welcomed();
        $echo = fn (string $appId) => ['sender' => ['id' => self::PAGE], 'recipient' => ['id' => self::SENDER], 'message' => ['mid' => 'm_'.uniqid(), 'is_echo' => true, 'app_id' => $appId, 'text' => 'رد الفريق']];

        $this->webhook($echo('555'))->assertOk();
        $this->assertFalse($conversation->refresh()->botPaused());
        $this->assertSame(0, $conversation->messages()->where('direction', 'note')->count());

        $this->webhook($echo('263902037430900'))->assertOk();
        $this->assertTrue($conversation->refresh()->botPaused());
        $this->assertStringContainsString('رد الفريق', (string) $conversation->messages()->where('direction', 'note')->sole()->body);

        $this->webhook($this->message('سؤال'))->assertOk();
        $this->assertCount(0, $this->sent());
    }

    public function test_an_attachment_goes_to_the_team_and_a_sticker_gets_nothing(): void
    {
        $this->fakeMeta();
        $conversation = $this->welcomed();

        $this->webhook(['message' => ['mid' => 'm_1', 'attachments' => [['type' => 'image', 'payload' => ['sticker_id' => 369239263222822]]]]])->assertOk();
        $this->assertCount(0, $this->sent());

        $this->webhook(['message' => ['mid' => 'm_2', 'attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://example.test/r']]]]])->assertOk();
        $this->assertSame(PlatformAutoReply::HANDOVER_TEXT, $this->sent()->first()[0]['message']['text']);
        $this->assertTrue($conversation->refresh()->botPaused());
        $this->assertSame('[ملف]', $conversation->messages()->where('direction', 'in')->latest('id')->first()->body);
    }

    public function test_without_a_token_the_answer_is_marked_not_sent(): void
    {
        Http::fake();
        config(['whatsapp.page.token' => null, 'whatsapp.meta.token' => 'wa-token']);
        $conversation = $this->welcomed();
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'أهلا'])->assertSessionHasErrors('body');

        $this->assertSame('failed', $conversation->messages()->where('admin_id', $admin->id)->sole()->status);
        Http::assertNothingSent();
    }

    public function test_the_team_answers_from_the_inbox_with_no_whatsapp_link_or_template_on_the_page(): void
    {
        $this->fakeMeta();
        $conversation = $this->welcomed();
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/inbox/'.$conversation->id))->assertOk()->assertDontSee('wa.me/'.self::SENDER)->assertSee(__('Messenger'));
        $this->post($this->adminUrl("/inbox/{$conversation->id}/reply"), ['body' => 'أهلا'])->assertSessionHasNoErrors();

        $this->assertSame('أهلا', $this->sent()->first()[0]['message']['text']);
        $this->assertTrue($conversation->refresh()->botPaused());
    }

    public function test_deleting_a_conversation_needs_inbox_manage_and_takes_its_messages(): void
    {
        $conversation = $this->welcomed();
        $field = PlatformAdmin::query()->create(['name' => 'Field', 'email' => 'field@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'field_sales']);
        $this->actingAs($field, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/delete"))->assertForbidden();
        $this->assertTrue($conversation->fresh()->exists);

        $support = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 'sara@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
        $this->actingAs($support, 'admin')->post($this->adminUrl("/inbox/{$conversation->id}/delete"))->assertRedirect();

        $this->assertNull($conversation->fresh());
        $this->assertDatabaseCount('platform_conversation_messages', 0);
    }
}
