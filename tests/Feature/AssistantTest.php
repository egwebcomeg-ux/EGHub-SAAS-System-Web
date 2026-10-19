<?php

namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Services\Assistant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/** The website assistant: off without a key, answers from Claude with hand-off to WhatsApp, logged for the admin. */
class AssistantTest extends TenantTestCase
{
    private function fakeClaude(string $text, string $stop = 'end_turn'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 120, 'cache_read_input_tokens' => 2000, 'output_tokens' => 40],
        ])]);
    }

    public function test_without_a_key_the_endpoint_is_unavailable_and_the_widget_is_not_rendered(): void
    {
        config(['assistant.key' => '']);
        PlatformSetting::put('sales_whatsapp', '01271133655');
        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertStatus(503);
        // the "Ask us" button then opens the sales WhatsApp directly
        $this->get($this->menuUrl(''))->assertOk()->assertDontSee('id="asst"', false)->assertDontSee('data-assistant-open', false)
            ->assertSee('https://wa.me/201271133655', false);
    }

    public function test_it_answers_from_claude_with_the_live_prices_in_the_reference_and_logs_the_turn(): void
    {
        config(['assistant.key' => 'test-key']);
        PlatformSetting::put('sales_whatsapp', '01271133655');
        $this->fakeClaude("الباقة الاحترافية بـ 399 جنيه في الشهر.\n\n**مع** خصم على 12 شهر.");

        $res = $this->postJson($this->menuUrl('assistant'), [
            'lang' => 'ar', 'conversation' => 'abc123',
            'messages' => [['role' => 'user', 'content' => 'الباقات بكام؟']],
        ])->assertOk()->assertJsonPath('handoff', false);
        $this->assertStringNotContainsString('**', $res->json('reply'));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $system = implode("\n", array_column($body['system'], 'text'));

            return $request->hasHeader('x-api-key', 'test-key')
                && str_contains($system, '1,250')                       // the Complete price from the database
                && str_contains($system, 'فرع إضافي')                   // an add-on still on sale
                && str_contains($system, 'wa.me/201271133655')           // the sales number for hand-offs
                && ($body['system'][1]['cache_control']['type'] ?? '') === 'ephemeral'
                && $body['messages'][0]['content'] === 'الباقات بكام؟';
        });

        $row = AssistantMessage::query()->firstOrFail();
        $this->assertSame(['abc123', 'الباقات بكام؟', false, 2120, 40], [$row->conversation, $row->question, $row->handoff, $row->tokens_in, $row->tokens_out]);
        $this->assertNotSame('', $row->visitor);

        // the widget is on the landing pages
        $this->get($this->menuUrl(''))->assertOk()->assertSee('id="asst"', false)->assertSee('wa.me/201271133655', false);
    }

    public function test_hand_off_when_the_model_marks_it_or_the_visitor_asks_for_a_person(): void
    {
        config(['assistant.key' => 'test-key']);
        $this->fakeClaude('أكيد، فريقنا هيساعدك على واتساب. '.Assistant::HANDOFF);
        $res = $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'عندي مشكلة في الفاتورة']]])
            ->assertOk()->assertJsonPath('handoff', true);
        $this->assertStringNotContainsString(Assistant::HANDOFF, $res->json('reply'));

        $this->fakeClaude('تمام.');
        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'عايز اكلم حد من المبيعات']]])
            ->assertOk()->assertJsonPath('handoff', true);
        $this->assertSame(2, AssistantMessage::query()->where('handoff', true)->count());
    }

    public function test_malformed_turns_are_cleaned_and_provider_errors_fail_softly(): void
    {
        config(['assistant.key' => 'test-key']);
        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'assistant', 'content' => 'x']]])->assertStatus(422);
        $this->postJson($this->menuUrl('assistant'), ['messages' => []])->assertStatus(422);

        // consecutive same-role turns collapse and the leading assistant turn is dropped
        $this->fakeClaude('ok');
        $this->postJson($this->menuUrl('assistant'), ['messages' => [
            ['role' => 'assistant', 'content' => 'welcome'], ['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'b'],
        ]])->assertOk();
        Http::assertSent(fn ($r) => count($r->data()['messages']) === 1 && $r->data()['messages'][0]['content'] === 'a');
    }

    public function test_a_provider_error_fails_softly_and_is_not_logged(): void
    {
        config(['assistant.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);
        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertStatus(502)->assertJsonPath('error', 'failed');
        $this->assertSame(0, AssistantMessage::query()->count());
    }

    public function test_gemini_answers_when_it_is_the_chosen_provider(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.key' => '', 'assistant.gemini.key' => 'g-key', 'assistant.gemini.model' => 'gemini-3.1-flash-lite']);
        $this->assertTrue(Assistant::enabled());
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'الباقة المجانية فيها المنيو والـQR.']], 'role' => 'model'], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 3000, 'candidatesTokenCount' => 25],
        ])]);

        $this->postJson($this->menuUrl('assistant'), ['messages' => [
            ['role' => 'user', 'content' => 'إيه الباقات؟'], ['role' => 'assistant', 'content' => 'عندنا خمس باقات.'], ['role' => 'user', 'content' => 'والمجانية؟'],
        ]])->assertOk()->assertJsonPath('reply', 'الباقة المجانية فيها المنيو والـQR.')->assertJsonPath('handoff', false);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'models/gemini-3.1-flash-lite:generateContent')
                && $request->hasHeader('x-goog-api-key', 'g-key')
                && str_contains($body['system_instruction']['parts'][0]['text'], '1,250')
                && $body['contents'][1]['role'] === 'model'
                && $body['contents'][2]['parts'][0]['text'] === 'والمجانية؟';
        });
        $this->assertSame([3000, 25], [AssistantMessage::query()->value('tokens_in'), AssistantMessage::query()->value('tokens_out')]);

        // the Anthropic key alone does not switch Gemini on
        config(['assistant.gemini.key' => '', 'assistant.key' => 'a-key']);
        $this->assertFalse(Assistant::enabled());
    }

    public function test_gemini_high_demand_is_retried_then_falls_back_to_the_second_model(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.key' => 'g-key', 'assistant.gemini.model' => 'gemini-3.8-flash', 'assistant.gemini.fallback' => 'gemini-3.5-flash']);
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->url();
            if (str_contains($request->url(), 'gemini-3.8-flash')) {
                return Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'high demand']], 503);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'تمام من الموديل الاحتياطي.']]], 'finishReason' => 'STOP']]]);
        });

        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'الباقات بكام؟']]])
            ->assertOk()->assertJsonPath('reply', 'تمام من الموديل الاحتياطي.');
        $this->assertCount(3, $calls); // main model twice, then the fallback once
        $this->assertStringContainsString('gemini-3.5-flash', $calls[2]);
    }

    public function test_a_hung_gemini_model_moves_to_the_fallback_at_once(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.key' => 'g-key', 'assistant.gemini.model' => 'gemini-3.8-flash', 'assistant.gemini.fallback' => 'gemini-3.5-flash']);
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->url();
            if (str_contains($request->url(), 'gemini-3.8-flash')) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'رد من الاحتياطي.']]], 'finishReason' => 'STOP']]]);
        });

        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'ينفع أشتري موديول واحد بس؟']]])
            ->assertOk()->assertJsonPath('reply', 'رد من الاحتياطي.');
        $this->assertCount(2, $calls); // the hung model once, then straight to the fallback
    }

    public function test_a_bad_gemini_key_is_not_retried(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.key' => 'bad', 'assistant.gemini.model' => 'gemini-3.8-flash', 'assistant.gemini.fallback' => 'gemini-3.5-flash']);
        Http::fake(fn () => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400));
        $this->postJson($this->menuUrl('assistant'), ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertStatus(502);
        Http::assertSentCount(1);
    }

    public function test_admin_sees_the_log_and_the_stats(): void
    {
        AssistantMessage::query()->create(['conversation' => 'c1', 'lang' => 'ar', 'question' => 'الباقات بكام', 'answer' => '399', 'handoff' => false, 'tokens_in' => 10, 'tokens_out' => 5]);
        AssistantMessage::query()->create(['conversation' => 'c2', 'lang' => 'ar', 'question' => 'عايز اكلم حد', 'answer' => 'واتساب', 'handoff' => true]);
        $this->get($this->adminUrl('/assistant'))->assertRedirect();
        $admin = PlatformAdmin::query()->create(['name' => 'EgWeb', 'email' => 'a@egmenu.test', 'password' => bcrypt('Secret-pass-123')]);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/assistant'))->assertOk()->assertSee('الباقات بكام')->assertSee('عايز اكلم حد');
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/assistant?handoff=1'))->assertOk()->assertDontSee('الباقات بكام')->assertSee('عايز اكلم حد');
    }
}
