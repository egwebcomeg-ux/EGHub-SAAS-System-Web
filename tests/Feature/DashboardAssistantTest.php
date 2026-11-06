<?php

namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\PlatformSetting;
use App\Services\DashboardAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * «اسألني» in the dashboard: only on plans with dashboard_assistant, answers from this user's own pages, links only to
 * pages they may open, hands over to support, and stops at the restaurant's daily ceiling.
 */
class DashboardAssistantTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['assistant.provider' => 'anthropic', 'assistant.key' => 'test-key']);
        PlatformSetting::put('support_whatsapp', '01271133655');
    }

    private function fakeClaude(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 900, 'output_tokens' => 60],
        ])]);
    }

    private function ask(string $question): TestResponse
    {
        return $this->postJson($this->dashUrl('/assistant'), ['conversation' => 'c1', 'messages' => [['role' => 'user', 'content' => $question]]]);
    }

    public function test_it_answers_from_the_users_pages_and_links_only_pages_they_may_open(): void
    {
        $place = $this->restaurant('cafe-a', 'full');
        $this->fakeClaude("• من الصفحة الرئيسية اضغط «وقّف الطلبات».\n[[go:stop-orders]] [[go:dash.pos]] [[go:https://evil.test]]");

        $this->actingAs($this->owner($place));
        $res = $this->ask('إزاي أقفل الطلب؟')->assertOk()->assertJsonPath('handoff', false);

        $this->assertSame('• من الصفحة الرئيسية اضغط «وقّف الطلبات».', $res->json('reply'));
        // the POS is not in the Complete plan and a typed address is never a link: one button, to the home page
        $this->assertSame([['label' => 'إيقاف الطلبات أو تشغيلها', 'url' => 'http://app.egmenu.test']], $res->json('links'));
        Http::assertSent(function ($request) {
            $system = implode("\n", array_column($request->data()['system'], 'text'));

            return str_contains($system, '- stop-orders: إيقاف الطلبات أو تشغيلها')
                && str_contains($system, '«وقّف الطلبات»')
                && str_contains($system, 'NOT IN THIS PLAN')
                && str_contains($system, 'Restaurant cafe-a');
        });
        $row = AssistantMessage::query()->firstOrFail();
        $this->assertSame('dash-cafe-a-c1', $row->conversation);
        $this->assertSame('إزاي أقفل الطلب؟', $row->question);
    }

    public function test_a_cashier_is_not_told_about_the_settings_pages(): void
    {
        $place = $this->restaurant('cafe-a', 'full');
        $this->fakeClaude("المدير بس يقدر يعمل ده.\n[[go:stop-orders]]");

        $this->actingAs($this->staff($place, 'cashier', 'cashier@cafe-a.test'));
        $this->ask('إزاي أقفل الطلب؟')->assertOk()->assertJsonPath('links', []);
        Http::assertSent(fn ($request) => ! str_contains(implode("\n", array_column($request->data()['system'], 'text')), '- stop-orders:'));
    }

    public function test_a_question_it_cannot_answer_goes_to_support(): void
    {
        $place = $this->restaurant('cafe-a', 'full');
        $this->fakeClaude('دعم EgHub هيساعدك في ده على واتساب. [[HUMAN]]');

        $this->actingAs($this->owner($place));
        $this->ask('الفاتورة اتخصمت مرتين')->assertOk()
            ->assertJsonPath('handoff', true)->assertJsonPath('reply', 'دعم EgHub هيساعدك في ده على واتساب.')
            ->assertJsonPath('whatsapp', '201271133655');
    }

    public function test_only_plans_with_the_assistant_get_it(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro));
        $this->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-dash-asst-open', false);
        $this->ask('إزاي أقفل الطلب؟')->assertForbidden();

        $full = $this->restaurant('cafe-full', 'full');
        $this->actingAs($this->owner($full));
        $this->get($this->dashUrl('/'))->assertOk()->assertSee('data-dash-asst-open', false);
    }

    public function test_a_restaurant_stops_at_its_daily_questions(): void
    {
        $place = $this->restaurant('cafe-a', 'full');
        Cache::put('dash-assistant:cafe-a:'.local_today(), DashboardAssistant::DAILY_LIMIT, now()->addDay());
        Http::fake();

        $this->actingAs($this->owner($place));
        $this->ask('إزاي أقفل الطلب؟')->assertStatus(429)->assertJsonPath('whatsapp', '201271133655');
        Http::assertNothingSent();
    }
}
