<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\AddonPrice;
use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Models\Prospect;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsAppTemplatesChanged;
use App\Services\ErrorAlert;
use App\Services\WhatsApp\PlatformAutoReply;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TenantTestCase;

/** The bot meets a restaurant that signed up; the EgHub number sells and charges nothing while it cannot send; failures reach the owners. */
class NewRestaurantBotAndPlatformSendingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.meta.phone_id' => '999', 'whatsapp.meta.token' => 'tok', 'whatsapp.business_account_id' => '555', 'assistant.key' => 'k']);
        PlatformSetting::put(PlatformAutoReply::SETTING, '1');
    }

    private function signUp(): void
    {
        $this->post($this->menuUrl('register'), [
            'name' => 'كافيه جديد', 'slug' => 'new-cafe', 'owner_name' => 'Sara', 'email' => 'sara@new.test',
            'phone' => '01066666666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertRedirect();
    }

    private string $welcomeStatus = 'APPROVED';

    private function fakeMeta(): void
    {
        Http::fake(['graph.facebook.com/*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(['data' => [['name' => 'egmenu_welcome', 'status' => $this->welcomeStatus]]])
            : Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
    }

    public function test_a_signup_waits_for_the_approved_template_then_is_welcomed_once(): void
    {
        $this->welcomeStatus = 'PENDING';
        $this->fakeMeta();
        $this->signUp();
        $conversation = PlatformConversation::query()->sole();
        $this->assertSame('new-cafe', $conversation->tenant_id);
        $this->assertSame('201066666666', $conversation->phone);

        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();
        $this->assertSame(0, $conversation->messages()->count());

        $this->welcomeStatus = 'APPROVED';
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertStringContainsString('Sara', $conversation->messages()->sole()->body);
    }

    public function test_the_button_sends_nothing_before_approval_and_a_failed_welcome_is_tried_again_after_it(): void
    {
        $this->welcomeStatus = 'PENDING';
        $this->fakeMeta();
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $tenant = $this->restaurant('cafe-a');
        $this->owner($tenant)->forceFill(['phone' => '01011111111'])->save();
        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 's@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support', 'is_active' => true]);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/conversation'))->assertRedirect()->assertSessionHas('ok');
        $conversation = PlatformConversation::query()->sole();
        $this->assertSame(0, $conversation->messages()->count());
        $this->post($this->adminUrl('/inbox/start'), ['phone' => '01022222222', 'name' => 'Ali'])->assertSessionHasErrors('phone');
        $this->assertSame(1, PlatformConversation::query()->count());

        // Meta refused the first try; it is tried again only a day later, as Meta asks
        $conversation->messages()->create(['direction' => 'out', 'body' => 'x', 'status' => 'failed', 'error' => 'This message was not delivered to maintain healthy ecosystem engagement.']);
        $this->welcomeStatus = 'APPROVED';
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();
        $this->assertSame(1, $conversation->messages()->count());
        $this->actingAs($admin, 'admin')->get($this->adminUrl("/inbox/{$conversation->id}"))->assertOk()->assertSee('ميتا ما وصلتهاش');

        $this->travel(25)->hours();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();
        $this->artisan('eghub:welcome-new-restaurants')->assertSuccessful();

        $this->assertSame(1, $conversation->messages()->where('status', 'sent')->count());
        $this->flushSession()->actingAs($admin, 'admin')->get($this->adminUrl("/inbox/{$conversation->id}"))->assertOk()->assertSee('لسه ما ردش');
    }

    public function test_a_restaurants_conversation_opens_from_its_page_for_who_handles_the_inbox_only(): void
    {
        $this->fakeMeta();
        $tenant = $this->restaurant('cafe-a');
        $this->owner($tenant)->forceFill(['phone' => '01011111111'])->save();
        $url = $this->adminUrl('/tenants/cafe-a/conversation');

        $viewer = PlatformAdmin::query()->create(['name' => 'Viewer', 'email' => 'v@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'finance', 'is_active' => true]);
        $this->actingAs($viewer, 'admin')->post($url);
        $this->assertSame(0, PlatformConversation::query()->count());

        $admin = PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 's@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support', 'is_active' => true]);
        $this->actingAs($admin, 'admin')->post($url)->assertRedirect();
        $this->actingAs($admin, 'admin')->post($url)->assertRedirect();

        $conversation = PlatformConversation::query()->sole();
        $this->assertSame('cafe-a', $conversation->tenant_id);
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_the_bot_asks_a_restaurant_for_what_it_is_missing_from_that_restaurant_only(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ابعتلنا المنيو']], 'stop_reason' => 'end_turn']), 'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $this->restaurant('cafe-a');
        $conversation = PlatformConversation::query()->create(['channel' => 'whatsapp', 'phone' => '201011111111', 'name' => 'Sara', 'tenant_id' => 'cafe-a', 'last_inbound_at' => now()]);
        $message = $conversation->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'أهلا', 'status' => 'received']);

        app(PlatformAutoReply::class)->answer($message);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'NEW RESTAURANT'));
    }

    public function test_the_bot_knows_the_menu_offer_only_for_the_number_of_a_place_that_got_it(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ابعت المنيو']], 'stop_reason' => 'end_turn']), 'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $place = Prospect::query()->create(['name' => 'مطعم العرض', 'category' => 'restaurant', 'phone' => '01066666666']);
        $place->events()->create(['type' => 'whatsapp', 'outcome' => 'sent', 'meta' => ['offer' => true]]);
        $ask = function (string $phone): void {
            $conversation = PlatformConversation::query()->create(['channel' => 'whatsapp', 'phone' => $phone, 'name' => 'x', 'last_inbound_at' => now()]);
            app(PlatformAutoReply::class)->answer($conversation->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'عايز منيو تجريبي', 'status' => 'received']));
        };
        $offerAsked = fn () => collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'anthropic') && str_contains(json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE), 'MENU OFFER'))->count();

        $ask('201011111111');
        $this->assertSame(0, $offerAsked());

        $ask('201066666666');
        $this->assertSame(1, $offerAsked());
    }

    public function test_while_the_eghub_number_is_off_nothing_is_sold_or_charged_and_nothing_pretends_to_be_sent(): void
    {
        config(['whatsapp.platform_sending' => false]);
        $tenant = $this->restaurant('cafe-a', 'business');
        app(Credits::class)->add($tenant, 5, 'adjustment');
        $pack = AddonPrice::query()->create(['key' => 'wa_test', 'type' => 'credits', 'credits' => 500, 'name' => 'رسايل', 'name_en' => 'Messages', 'monthly_price' => 149, 'is_active' => true]);

        $message = $this->as($tenant, fn () => app(WhatsApp::class)->deliver(app(WhatsApp::class)->record('01012345678', 'order_ready', ['name' => 'A', 'restaurant' => 'B', 'number' => 1, 'link' => 'x'])));
        $this->assertSame('failed', $message->status);
        $this->assertSame(WhatsAppMessage::PLATFORM_OFF, $message->error);
        $this->assertSame(5, app(Credits::class)->balance($tenant));

        $this->actingAs($this->owner($tenant));
        $this->get($this->dashUrl('/billing'))->assertOk()->assertDontSee('id="whatsapp-credits"', false)->assertSee('data-whatsapp-platform-off', false);
        $this->post($this->dashUrl("/billing/addon/{$pack->key}"))->assertNotFound();

        config(['whatsapp.platform_sending' => true]);
        $this->get($this->dashUrl('/billing'))->assertOk()->assertSee('id="whatsapp-credits"', false)->assertDontSee('data-whatsapp-platform-off', false);
    }

    public function test_a_changed_template_is_told_once_and_a_failed_reading_tells_nothing(): void
    {
        Notification::fake();
        PlatformAdmin::query()->create(['name' => 'Sara', 'email' => 's@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support', 'is_active' => true]);
        $this->fakeMeta();
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        $this->artisan('eghub:whatsapp-templates')->assertSuccessful();
        Notification::assertSentTimes(WhatsAppTemplatesChanged::class, 1);
    }

    public function test_an_error_reaches_the_owners_once_in_a_while_and_only_when_switched_on(): void
    {
        config(['mail.default' => 'array']);
        PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'o@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner', 'is_active' => true]);
        $error = new RuntimeException('boom');

        ErrorAlert::report($error);
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());

        config(['eghub.error_alerts' => true]);
        ErrorAlert::report($error);
        ErrorAlert::report($error);
        ErrorAlert::notice('The queue is stuck', 'since 10:00');

        $this->assertCount(2, Mail::getSymfonyTransport()->messages());
    }
}
