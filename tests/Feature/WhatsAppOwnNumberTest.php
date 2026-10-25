<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Backups\TenantBackups;
use App\Services\Marketing\Campaigns;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\WhatsApp\OwnNumber;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;
use ZipArchive;

/**
 * "Bring your own number": a restaurant connects its own WhatsApp Business number (Cloud API credentials it pastes),
 * messages with an approved template go out from it without EgHub credits, replies are routed back to that
 * restaurant only, and everything else falls back to the EgHub number. Meta is never called for real.
 */
class WhatsAppOwnNumberTest extends TenantTestCase
{
    private const TOKEN = 'EAAGownRestaurantPermanentToken0123456789wxyz';

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private const PLATFORM_SECRET = 'platform-app-secret';

    /** Graph API template list returned by the fake (name => language => STATUS). */
    private array $metaTemplates = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.app_secret' => self::PLATFORM_SECRET, 'whatsapp.meta.phone_id' => '999000999']);
        Http::preventStrayRequests();
    }

    /** A fake Graph API for WABA 111222333 with phone number 444555666 (and any other phone id for messages). */
    private function fakeMeta(array $phone = [], int $phoneStatus = 200): void
    {
        Http::fake(function (HttpRequest $request) use ($phone, $phoneStatus) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            return match (true) {
                str_ends_with($path, '/messages') => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
                str_ends_with($path, '/111222333/phone_numbers') => Http::response(['data' => [['id' => '444555666']]]),
                str_ends_with($path, '/111222333/subscribed_apps') => Http::response(['success' => true]),
                str_ends_with($path, '/111222333/message_templates') && $method === 'GET' => Http::response(['data' => collect($this->metaTemplates)
                    ->flatMap(fn ($langs, $name) => collect($langs)->map(fn ($status, $lang) => ['name' => $name, 'language' => $lang, 'status' => $status])->values())->values()->all()]),
                str_ends_with($path, '/111222333/message_templates') => Http::response(['id' => '77'.random_int(1, 999), 'status' => 'PENDING', 'category' => $request['category']]),
                str_ends_with($path, '/444555666') => Http::response($phone ?: ['display_phone_number' => '+20 10 1234 5678', 'verified_name' => 'Arafa Grill',
                    'quality_rating' => 'GREEN', 'name_status' => 'APPROVED', 'id' => '444555666'], $phoneStatus),
                str_ends_with($path, '/111222333') => Http::response(['id' => '111222333', 'name' => 'Arafa WABA']),
                default => Http::response(['error' => ['message' => 'unexpected '.$path]], 500),
            };
        });
    }

    /** @return array<string,string> */
    private function form(array $overrides = []): array
    {
        return $overrides + ['waba_id' => '111222333', 'phone_number_id' => '444555666', 'access_token' => self::TOKEN, 'app_secret' => self::SECRET];
    }

    /** A restaurant whose own number is connected with the given templates approved (Arabic). */
    private function connected(Tenant $tenant, string $phoneId, string $secret, array $approved = ['egmenu_order_ready', 'egmenu_order_confirm']): WhatsAppConnection
    {
        return $this->as($tenant, fn () => WhatsAppConnection::query()->create([
            'waba_id' => '111222333', 'phone_number_id' => $phoneId, 'access_token' => 'token-of-'.$tenant->id,
            'app_secret' => $secret, 'webhook_key' => random_token(16), 'verify_token' => random_token(20), 'status' => 'connected',
            'templates' => collect($approved)->mapWithKeys(fn ($name) => [$name => ['ar' => 'approved']])->all(),
        ]));
    }

    private function connection(Tenant $tenant): ?WhatsAppConnection
    {
        return $this->as($tenant, fn () => WhatsAppConnection::query()->first());
    }

    private function message(Tenant $tenant, string $template = 'order_ready'): WhatsAppMessage
    {
        return $this->as($tenant, fn () => app(WhatsApp::class)->queue('01012345678', $template, ['name' => 'Mona', 'restaurant' => 'Arafa', 'number' => '7', 'link' => 'https://x.test']))->fresh();
    }

    /** A cash-on-delivery delivery order from the menu link: the guest is asked to confirm it. */
    private function codOrder(Tenant $tenant): Order
    {
        $this->as($tenant, fn () => Settings::put(['wa_order_confirm' => '1']));
        $item = $this->item($tenant, 'Burger', 120);

        return $this->as($tenant, function () use ($item) {
            $branch = Branch::query()->first();
            $zone = DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => 'Maadi', 'fee' => 20, 'is_active' => true]);

            return app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => '01012345678', 'customer_name' => 'Sohail',
                'source' => 'online', 'type' => 'delivery', 'delivery_zone' => $zone, 'delivery_address' => ['zone' => 'Maadi', 'text' => '12 Street 9'],
            ], $branch);
        })->fresh();
    }

    /** A Meta payload for $phoneId signed with $secret, to $url. */
    private function postMeta(string $url, string $phoneId, array $value, ?string $secret): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => $value + [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneId, 'display_phone_number' => '201000000000'],
        ]]]]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', $url, [], [], [], $headers, $raw);
    }

    private function tap(Order $order, string $action = 'confirm'): array
    {
        return ['messages' => [['from' => '201012345678', 'id' => 'wamid.'.uniqid(), 'type' => 'button', 'button' => ['payload' => "oc:$action:".$order->confirm_token, 'text' => 'x']]]];
    }

    private function ownUrl(WhatsAppConnection $connection): string
    {
        return $this->menuUrl('webhooks/whatsapp/'.$connection->webhook_key);
    }

    public function test_the_owner_connects_the_number_and_our_templates_are_created_in_its_account(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form())
            ->assertRedirect()->assertSessionHas('ok')->assertSessionHasNoErrors();

        $c = $this->connection($a);
        $this->assertSame(['connected', '+20 10 1234 5678', 'Arafa Grill', 'approved', 'green'], [$c->status, $c->display_phone, $c->verified_name, $c->name_status, $c->quality_rating]);
        $this->assertNotNull($c->subscribed_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $c->webhook_key);
        // encrypted at rest, decrypted on read
        $raw = DB::table('whatsapp_connections')->first();
        $this->assertStringNotContainsString(self::TOKEN, $raw->access_token);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw->app_secret);
        $this->assertSame([self::TOKEN, self::SECRET], [$c->access_token, $c->app_secret]);
        $this->assertArrayNotHasKey('access_token', $c->toArray());

        // the check, the subscription and one template per name and language, all with the restaurant's token
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/444555666?fields=display_phone_number%2Cverified_name%2Cquality_rating%2Cname_status')
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/111222333/subscribed_apps'));
        $created = Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/111222333/message_templates'));
        $this->assertCount(14, $created); // 7 template names × ar, en
        $confirm = $created->first(fn ($pair) => $pair[0]['name'] === 'egmenu_order_confirm' && $pair[0]['language'] === 'ar')[0];
        $this->assertSame('UTILITY', $confirm['category']);
        $this->assertStringContainsString('أهلاً {{1}} 🙏 طلبك رقم {{2}} من {{3}}', $confirm['components'][0]['text']);
        $this->assertCount(6, $confirm['components'][0]['example']['body_text'][0]);
        $this->assertSame(['تأكيد الطلب', 'تعديل الطلب', 'إلغاء الطلب'], array_column($confirm['components'][1]['buttons'], 'text'));
        $campaign = $created->first(fn ($pair) => $pair[0]['name'] === 'egmenu_campaign' && $pair[0]['language'] === 'ar')[0];
        $this->assertSame('MARKETING', $campaign['category']);
        // Meta refuses a body that starts or ends with a variable
        $this->assertSame("أهلاً 👋\n{{1}}\nشكراً ليك 🙏", $campaign['components'][0]['text']);
        $this->assertSame('pending', $c->templateStatus('egmenu_order_confirm', 'ar'));

        // the page shows the number, its own webhook URL and the masked token, never the token or the secret
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings/whatsapp-number'))->assertOk()
            ->assertSee('Arafa Grill')->assertSee('data-wa-number-status="connected"', false)
            ->assertSee('/webhooks/whatsapp/'.$c->webhook_key, false)->assertSee($c->verify_token)
            ->assertSee('••••••••wxyz')->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET)
            ->assertSee('data-wa-template="egmenu_order_confirm:ar"', false);
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings?tab=messages'))->assertOk()
            ->assertSee('data-wa-own-number-link', false)->assertSee('+20 10 1234 5678');

        // the audit names the number, never a secret
        $audit = $this->as($a, fn () => AuditLog::query()->where('action', 'whatsapp.own_number.saved')->sole());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($audit->meta));
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit->meta));
    }

    public function test_refresh_reads_the_approval_status_and_only_submits_missing_templates(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $this->metaTemplates = ['egmenu_order_ready' => ['ar' => 'APPROVED', 'en' => 'REJECTED'], 'someone_elses' => ['ar' => 'APPROVED']];
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form());
        $this->assertCount(12, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/message_templates')));

        $this->metaTemplates += ['egmenu_order_confirm' => ['ar' => 'APPROVED', 'en' => 'PENDING']];
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number/templates'))->assertSessionHas('ok');

        $c = $this->connection($a);
        $this->assertSame(['approved', 'rejected', 'approved'], [$c->templateStatus('egmenu_order_ready', 'ar'), $c->templateStatus('egmenu_order_ready', 'en'), $c->templateStatus('egmenu_order_confirm', 'ar')]);
        $this->assertArrayNotHasKey('someone_elses', $c->templates);
    }

    public function test_a_failed_test_explains_the_reason_and_never_shows_the_token(): void
    {
        $this->fakeMeta(['error' => ['code' => 190, 'message' => 'Error validating access token '.self::TOKEN]], 401);
        $a = $this->restaurant('cafe-a');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form())->assertSessionHasErrors('whatsapp_number');

        $c = $this->connection($a);
        $this->assertSame('failed', $c->status);
        $this->assertStringContainsString(__('The access token is invalid or expired. Generate a new one with the expiry set to “Never”.'), $c->last_error);
        $this->assertStringNotContainsString(self::TOKEN, $c->last_error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'message_templates') || str_contains($r->url(), 'subscribed_apps'));
    }

    public function test_the_form_is_validated_and_a_number_belongs_to_one_restaurant_only(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->connected($b, '444555666', self::SECRET);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form(['waba_id' => 'abc', 'app_secret' => 'short', 'access_token' => '']))
            ->assertSessionHasErrors(['waba_id', 'app_secret', 'access_token']);
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form())->assertSessionHasErrors('phone_number_id');
        // EgHub's own number cannot be claimed either
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number'), $this->form(['phone_number_id' => '999000999']))->assertSessionHasErrors('phone_number_id');
        $this->assertNull($this->connection($a));
        Http::assertNothingSent();
    }

    public function test_messages_use_the_restaurant_number_without_credits_once_their_template_is_approved(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $c = $this->connected($a, '444555666', self::SECRET);
        app(Credits::class)->add($a, 5, 'adjustment');

        $own = $this->message($a, 'order_ready');
        $this->assertSame(['sent', 'own', 0], [$own->status, $own->channel, $own->cost_credits]);
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/444555666/messages'
            && $r->hasHeader('Authorization', 'Bearer token-of-cafe-a') && $r['template']['name'] === 'egmenu_order_ready');

        // a template not approved in the restaurant's account: the EgHub number (log driver here), one credit
        $platform = $this->message($a, 'order_received');
        $this->assertSame(['sent', 'platform', 1], [$platform->status, $platform->channel, $platform->cost_credits]);
        $this->assertSame(4, app(Credits::class)->balance($a));
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/messages')));

        // a failed connection falls back too
        $c->forceFill(['status' => 'failed'])->save();
        $this->assertSame('platform', $this->message($a, 'order_ready')->channel);
    }

    public function test_a_restaurant_backup_never_holds_the_whatsapp_credentials(): void
    {
        Storage::fake('local');
        $a = $this->restaurant('cafe-a', 'full');
        $c = $this->connected($a, '444555666', self::SECRET);

        $backup = $this->as($a, fn () => app(TenantBackups::class)->runScheduled());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->storagePath())));
        $rows = json_decode($zip->getFromName('data/whatsapp_connections.json'), true);

        $this->assertSame('444555666', $rows[0]['phone_number_id']);
        $this->assertArrayNotHasKey('access_token', $rows[0]);
        $this->assertArrayNotHasKey('app_secret', $rows[0]);
        $this->assertArrayNotHasKey('verify_token', $rows[0]);
        $this->assertStringNotContainsString('token-of-cafe-a', json_encode($rows));
        $this->assertStringNotContainsString($c->verify_token, json_encode($rows));
    }

    public function test_a_campaign_from_the_restaurant_number_costs_no_credit(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona']));
        $preview = fn () => $this->as($a, fn () => app(Campaigns::class)->preview('all'));

        $this->assertSame(1, $preview()['cost']);
        $this->connected($a, '444555666', self::SECRET, ['egmenu_campaign']);
        $this->assertSame(['recipients' => 1, 'cost' => 0, 'balance' => 0], array_intersect_key($preview(), array_flip(['recipients', 'cost', 'balance'])));
    }

    public function test_an_expired_token_marks_the_connection_failed_and_the_next_message_falls_back(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 401)]);
        $a = $this->restaurant('cafe-a');
        $this->connected($a, '444555666', self::SECRET);
        app(Credits::class)->add($a, 1, 'adjustment');

        $failed = $this->message($a);
        $this->assertSame(['failed', 'own'], [$failed->status, $failed->channel]);
        $this->assertSame('failed', $this->connection($a)->status);
        $this->assertSame(1, app(Credits::class)->balance($a)); // nothing was charged

        $next = $this->message($a);
        $this->assertSame(['sent', 'platform'], [$next->status, $next->channel]);
    }

    public function test_replies_to_a_restaurant_number_reach_only_that_restaurant_and_are_answered_from_it(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $ca = $this->connected($a, '444555666', self::SECRET);
        $cb = $this->connected($b, '777888999', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        // the confirmation itself leaves from A's number, with no EgHub credit at all
        $order = $this->codOrder($a);
        $asked = $this->as($a, fn () => WhatsAppMessage::query()->sole());
        $this->assertSame(['order_confirm', 'own', 0, 'pending'], [$asked->template, $asked->channel, $asked->cost_credits, $order->confirm_status]);

        // B's URL (B's secret) carrying A's number, or B's number with A's order: ignored
        $this->postMeta($this->ownUrl($cb), '444555666', $this->tap($order), 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->assertOk();
        $this->postMeta($this->ownUrl($cb), '777888999', $this->tap($order), 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->assertOk();
        // A's URL signed with anything but A's app secret: refused
        $this->postMeta($this->ownUrl($ca), '444555666', $this->tap($order), self::PLATFORM_SECRET)->assertForbidden();
        $this->postMeta($this->ownUrl($ca), '444555666', $this->tap($order), null)->assertForbidden();
        $this->assertSame('pending', $order->fresh()->confirm_status);

        $this->postMeta($this->ownUrl($ca), '444555666', $this->tap($order), self::SECRET)->assertOk();
        $this->assertSame('confirmed', Order::query()->withoutGlobalScopes()->find($order->id)->confirm_status);
        $reply = $this->as($a, fn () => WhatsAppMessage::query()->where('template', 'session_reply')->sole());
        $this->assertSame(['sent', 'own', 0], [$reply->status, $reply->channel, $reply->cost_credits]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/444555666/messages') && ($r['type'] ?? '') === 'text');
        $this->assertNotNull($this->connection($a)->last_inbound_at);

        // a delivery status for A's message through B's URL changes nothing; through A's URL it moves forward
        $this->postMeta($this->ownUrl($cb), '777888999', ['statuses' => [['id' => $asked->provider_id, 'status' => 'read']]], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->assertSame('sent', $asked->fresh()->status);
        $this->postMeta($this->ownUrl($ca), '444555666', ['statuses' => [['id' => $asked->provider_id, 'status' => 'read']]], self::SECRET);
        $this->assertSame('read', $asked->fresh()->status);

        // an unknown key is a 404
        $this->postMeta($this->menuUrl('webhooks/whatsapp/'.str_repeat('a', 32)), '444555666', $this->tap($order), self::SECRET)->assertNotFound();
    }

    public function test_the_eghub_webhook_routes_a_restaurant_number_by_phone_number_id(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $this->connected($a, '444555666', self::SECRET);
        $order = $this->codOrder($a);

        // our own app subscribed to the restaurant's account (Embedded Signup later): signed with EgHub's secret
        $this->postMeta($this->menuUrl('webhooks/whatsapp'), '444555666', $this->tap($order), self::PLATFORM_SECRET)->assertOk();
        $this->assertSame('confirmed', $order->fresh()->confirm_status);
        $this->assertSame('own', $this->as($a, fn () => WhatsAppMessage::query()->where('template', 'session_reply')->sole()->channel));
    }

    public function test_the_handshake_of_the_restaurant_app_uses_its_own_verify_token(): void
    {
        config(['whatsapp.verify_token' => 'platform-verify']);
        $a = $this->restaurant('cafe-a');
        $c = $this->connected($a, '444555666', self::SECRET);
        $url = fn (string $token) => $this->ownUrl($c).'?hub.mode=subscribe&hub.challenge=42&hub.verify_token='.$token;

        $this->get($url('platform-verify'))->assertForbidden();
        $this->assertNull($this->connection($a)->webhook_verified_at);
        $this->get($url($c->verify_token))->assertOk()->assertSee('42');
        $this->assertNotNull($this->connection($a)->webhook_verified_at);
    }

    public function test_disconnecting_deletes_the_credentials_and_messages_go_back_to_the_eghub_number(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $this->connected($a, '444555666', self::SECRET);
        app(Credits::class)->add($a, 1, 'adjustment');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/whatsapp-number/disconnect'))->assertSessionHas('ok');

        $this->assertNull($this->connection($a));
        $this->assertSame(0, DB::table('whatsapp_connections')->count());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscribed_apps'));
        $this->assertSame('platform', $this->message($a)->channel);
        $this->assertSame(['whatsapp.own_number.disconnected'], $this->as($a, fn () => AuditLog::query()->pluck('action')->all()));
    }

    public function test_only_owners_and_managers_with_whatsapp_in_the_plan_reach_it(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->connected($a, '444555666', self::SECRET);

        $this->actingAs($this->staff($a, 'cashier', 'c@cafe-a.test'))->get($this->dashUrl('/settings/whatsapp-number'))->assertForbidden();
        $this->actingAs($this->staff($a, 'cashier', 'c2@cafe-a.test'))->post($this->dashUrl('/settings/whatsapp-number/disconnect'))->assertForbidden();
        $this->actingAs($this->staff($a, 'manager', 'm@cafe-a.test'))->get($this->dashUrl('/settings/whatsapp-number'))->assertOk()->assertSee(__('Save and connect'));
        $this->assertNotNull($this->connection($a));

        // a plan without WhatsApp messages: the page explains, nothing can be saved
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/settings/whatsapp-number'))->assertOk()->assertDontSee(__('Save and connect'));
        $this->actingAs($this->owner($basic))->post($this->dashUrl('/settings/whatsapp-number'), $this->form())->assertForbidden();
        $this->assertNull($this->connection($basic));
        $this->assertFalse($this->as($basic, fn () => OwnNumber::covers('order_ready')));
    }

    public function test_eghub_support_signed_in_from_the_admin_can_test_but_never_see_or_change_the_token(): void
    {
        $this->fakeMeta();
        $a = $this->restaurant('cafe-a');
        $c = $this->connected($a, '444555666', self::SECRET);
        $admin = PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'owner@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
        $ticket = $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/{$a->id}/support-access"))->headers->get('Location');
        $this->get($ticket);

        $this->get($this->dashUrl('/settings/whatsapp-number'))->assertOk()->assertSee('data-wa-number-support', false)
            ->assertDontSee('name="access_token"', false)->assertDontSee($c->verify_token)->assertDontSee('token-of-cafe-a');
        $this->post($this->dashUrl('/settings/whatsapp-number'), $this->form(['access_token' => 'EAAGsupportTriesToChangeTheToken123']))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/settings/whatsapp-number/disconnect'))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/settings/whatsapp-number/templates'))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/settings/whatsapp-number/test'))->assertSessionHas('ok');

        $this->assertSame('token-of-cafe-a', $this->connection($a)->access_token);
    }
}
