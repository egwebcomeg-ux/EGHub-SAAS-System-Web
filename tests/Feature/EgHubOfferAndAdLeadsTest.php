<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Models\Prospect;
use App\Services\Crm\CallScript;
use App\Services\Crm\WhatsAppOffer;
use App\Services\WhatsApp\PlatformAutoReply;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** The trial menu offer from the EgHub number after a yes on the phone, and the people our ads bring to WhatsApp. */
class EgHubOfferAndAdLeadsTest extends TenantTestCase
{
    private const SECRET = 'platform-app-secret';

    private const PHONE_ID = '999000999';

    private PlatformAdmin $caller;

    protected function setUp(): void
    {
        parent::setUp();
        CallScript::installDefaults();
        config(['whatsapp.app_secret' => self::SECRET, 'whatsapp.meta.phone_id' => self::PHONE_ID, 'whatsapp.meta.token' => 'meta-access', 'whatsapp.platform_sending' => true, 'whatsapp.number' => '201289500020']);
        PlatformSetting::put('whatsapp_template_status', json_encode(['eghub_trial_menu_offer' => 'APPROVED']));
        $this->caller = PlatformAdmin::query()->create(['name' => 'Mona Caller', 'email' => 'mona@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'sales']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
    }

    private function offer(Prospect $place, array $data = ['agreed' => '1']): TestResponse
    {
        return $this->actingAs($this->caller, 'admin')->from($this->adminUrl("/crm/prospects/{$place->id}"))
            ->post($this->adminUrl("/crm/prospects/{$place->id}/eghub-offer"), $data);
    }

    public function test_the_offer_goes_out_from_the_eghub_number_only_after_a_yes_and_once_a_month(): void
    {
        $place = Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);

        $this->offer($place, [])->assertSessionHasErrors('agreed');
        Http::assertNothingSent();

        $this->offer($place)->assertSessionHasNoErrors()->assertSessionHas('ok');
        Http::assertSent(fn ($request) => $request['to'] === '201066666666' && $request['template']['name'] === 'eghub_trial_menu_offer'
            && array_column($request['template']['components'][0]['parameters'], 'text') === ['Mona', 'مطعم الأصيل', '14']);
        $conversation = PlatformConversation::query()->sole();
        $this->assertStringContainsString('مطعم الأصيل', $conversation->messages()->sole()->body);
        $place->refresh();
        $this->assertSame('contacted', $place->stage);
        $this->assertTrue($place->events()->where('meta->via', 'eghub')->where('meta->agreed', true)->exists());
        // the EgHub number does not spend the caller's own daily offers
        $this->assertSame(0, app(WhatsAppOffer::class)->sentToday($this->caller));

        $this->offer($place)->assertSessionHasErrors('offer');
        Http::assertSentCount(1);
    }

    public function test_nothing_goes_out_while_the_number_is_off_or_the_template_waits(): void
    {
        $place = Prospect::query()->create(['name' => 'مطعم الأصيل', 'category' => 'restaurant', 'phone' => '01066666666']);
        PlatformSetting::put('whatsapp_template_status', json_encode(['eghub_trial_menu_offer' => 'PENDING']));
        $this->offer($place)->assertSessionHasErrors('offer');

        PlatformSetting::put('whatsapp_template_status', json_encode(['eghub_trial_menu_offer' => 'APPROVED']));
        config(['whatsapp.platform_sending' => false]);
        $this->offer($place)->assertSessionHasErrors('offer');
        $this->actingAs($this->caller, 'admin')->get($this->adminUrl("/crm/prospects/{$place->id}"))->assertOk()->assertSee('data-eghub-offer', false)->assertSee('لسه مش شغال');

        Http::assertNothingSent();
        $this->assertSame(0, $place->events()->count());
    }

    private function fromAd(string $from, string $name): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
            'messages' => [['id' => 'wamid.'.uniqid(), 'from' => $from, 'type' => 'text', 'text' => ['body' => 'عايز أعرف أكتر'],
                'referral' => ['source_type' => 'ad', 'source_id' => '1203', 'headline' => 'منيو QR لمطعمك ببلاش']]],
        ]]]]]]);

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    public function test_a_person_from_an_ad_becomes_a_place_the_bot_asks_for_its_menu_and_the_inbox_shows_it(): void
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, '0');
        $known = Prospect::query()->create(['name' => 'كافيه معروف', 'category' => 'cafe', 'phone' => '01077777777', 'source' => 'google']);

        $this->fromAd('201066666666', 'Hany')->assertOk();
        $this->fromAd('201077777777', 'Ali')->assertOk();

        $place = Prospect::query()->where('source', 'ad')->sole();
        $this->assertSame(['Hany', '01066666666'], [$place->name, $place->phone]);
        $this->assertSame(1, Prospect::query()->count() - 1);
        $this->assertTrue($known->events()->where('type', 'whatsapp')->exists());
        $conversation = PlatformConversation::query()->where('phone', '201066666666')->sole();
        $this->assertTrue($conversation->messages()->where('direction', 'note')->where('body', 'like', '%منيو QR لمطعمك ببلاش%')->exists());

        $support = PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'o@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
        $this->actingAs($support, 'admin')->get($this->adminUrl("/inbox/{$conversation->id}"))->assertOk()->assertSee('data-inbox-place', false)->assertSee('Hany');

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ابعت المنيو']], 'stop_reason' => 'end_turn']), 'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        config(['assistant.key' => 'k']);
        PlatformSetting::put(PlatformAutoReply::SETTING, '1');
        $conversation->messages()->create(['direction' => 'out', 'body' => 'الترحيب', 'status' => 'read']);
        app(PlatformAutoReply::class)->answer($conversation->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'عايز منيو', 'status' => 'received']));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'AD LEAD'));
    }
}
