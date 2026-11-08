<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Marketing\Campaigns;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Campaign messages go inside a template that names the restaurant and carries «إيقاف العروض»; a tap stops that
 * guest's offers from that restaurant only. Meta gets every body parameter on one line, never empty.
 */
class WhatsAppOffersOptOutTest extends TenantTestCase
{
    private const SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.app_secret' => self::SECRET]);
    }

    private function sendCampaign(Tenant $tenant, string $message): void
    {
        $this->as($tenant, function () use ($message) {
            $campaign = Campaign::query()->create(['name' => 'Offer', 'segment' => 'all', 'message' => $message, 'status' => 'sending', 'cursor' => 0]);
            app(Campaigns::class)->sendBatch($campaign->fresh());
        });
    }

    private function tap(string $payload, string $from = '201012345678'): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'messages' => [['from' => $from, 'id' => 'wamid.'.uniqid(), 'timestamp' => '1', 'type' => 'button', 'button' => ['payload' => $payload, 'text' => 'x']]],
        ]]]]]]);

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    private function optedOut(Tenant $tenant): bool
    {
        return (bool) $this->as($tenant, fn () => Customer::query()->sole()->whatsapp_opt_out);
    }

    public function test_the_stop_button_stops_only_that_guests_offers_from_that_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        foreach ([$a, $b] as $tenant) {
            app(Credits::class)->add($tenant, 5, 'adjustment');
            $this->as($tenant, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona']));
            $this->sendCampaign($tenant, 'Hi {name}, 20% off today');
        }

        $message = $this->as($a, fn () => WhatsAppMessage::query()->sole());
        $this->assertStringStartsWith('رسالة من ', $message->body);
        $this->assertSame(['restaurant', 'text'], array_keys($message->params));
        $this->assertSame('stop', $message->buttons[0]['id']);
        $token = $this->as($a, fn () => Customer::query()->sole()->card_token);
        $this->assertSame('wo:'.$token, $message->buttons[0]['payload']);

        $this->tap('wo:'.$token, '201099999999')->assertOk(); // another phone
        $this->tap('wo:'.str_repeat('a', 24))->assertOk();     // an unknown token
        $this->assertFalse($this->optedOut($a));

        $this->tap('wo:'.$token)->assertOk();
        $this->assertTrue($this->optedOut($a));
        $this->assertFalse($this->optedOut($b));
        $reply = $this->as($a, fn () => WhatsAppMessage::query()->where('template', 'session_reply')->sole());
        $this->assertStringContainsString('مش هتوصلك عروض تانية', $reply->body);

        // the next campaign skips them
        $this->sendCampaign($a, 'Another offer');
        $this->assertSame(1, $this->as($a, fn () => WhatsAppMessage::query()->where('template', 'campaign')->count()));
    }

    public function test_meta_gets_each_parameter_on_one_line_and_never_empty(): void
    {
        config(['whatsapp.driver' => 'meta', 'whatsapp.meta.phone_id' => '123', 'whatsapp.meta.token' => 'secret-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $a = $this->restaurant('cafe-a', 'business');
        app(Credits::class)->add($a, 5, 'adjustment');
        $this->as($a, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona']));

        $this->sendCampaign($a, "Hi {name}\n\n20% off\ttoday   only");

        Http::assertSent(function ($r) {
            $parameters = array_column($r['template']['components'][0]['parameters'], 'text');

            return $r['template']['name'] === 'eghub_campaign' && $parameters[1] === 'Hi Mona · 20% off today only'
                && $r['template']['components'][1]['sub_type'] === 'quick_reply';
        });
    }
}
