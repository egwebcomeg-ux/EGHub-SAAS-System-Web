<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\PhoneVerification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Phone confirmation over WhatsApp: the owner sends "EGMENU <code>" from their mobile to the platform number;
 * the inbound webhook (Meta or another provider) matches sender + code and stamps the user. Never a gate.
 */
class PhoneVerificationTest extends TenantTestCase
{
    private const SECRET = 'meta-app-secret';

    /** Gives $tenant's owner a known mobile and a known pending code. */
    private function pending(Tenant $tenant, string $phone, string $code): User
    {
        $owner = $this->owner($tenant);
        $owner->forceFill(['phone' => $phone, 'phone_verified_at' => null, 'phone_verification_code' => $code])->save();

        return $owner;
    }

    /** @param  array<string,mixed>  $payload */
    private function metaPayload(string $from, string $text): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '9'],
            'messages' => [['from' => $from, 'id' => 'wamid.x', 'timestamp' => '1', 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    /** @param  array<string,mixed>  $payload */
    private function postMeta(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], $headers, $raw);
    }

    public function test_signup_requires_a_valid_egyptian_mobile_and_stores_it_normalized(): void
    {
        $form = [
            'name' => 'كافيه', 'slug' => 'new-cafe', 'owner_name' => 'Sara', 'email' => 'sara@new.test',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ];
        $this->post($this->menuUrl('register'), $form + ['phone' => '0123'])->assertSessionHasErrors('phone');
        $this->post($this->menuUrl('register'), $form + ['phone' => '+20 10 6666 6666'])->assertRedirect();

        $owner = User::query()->where('email', 'sara@new.test')->firstOrFail();
        $this->assertSame('01066666666', $owner->phone);
        $this->assertNull($owner->phone_verified_at);
        $this->assertMatchesRegularExpression('/^['.PhoneVerification::ALPHABET.']{6}$/', $owner->phone_verification_code);

        // the same mobile cannot open a second account
        $this->post($this->menuUrl('register'), $form + ['slug' => 'other', 'email' => 'o@o.test', 'phone' => '01066666666'])
            ->assertSessionHasErrors('phone');
    }

    public function test_the_confirmation_page_links_to_whatsapp_with_the_prepared_message(): void
    {
        config(['whatsapp.number' => '201000000000']);
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');

        $this->actingAs($owner)->get($this->dashUrl('/phone'))->assertOk()
            ->assertSee('https://wa.me/201000000000?text=EGMENU%20AB2CD3', false)
            ->assertSee('EGMENU AB2CD3');
        $this->actingAs($owner)->get($this->dashUrl('/phone/status'))->assertJsonPath('verified', false);

        // the dashboard reminds until confirmed, then stops
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-phone-banner', false);
        $owner->markPhoneVerified();
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-phone-banner', false);
        $this->actingAs($owner)->get($this->dashUrl('/phone/status'))->assertJsonPath('verified', true);
    }

    public function test_the_platform_number_falls_back_to_the_support_setting_and_nothing_shows_without_one(): void
    {
        config(['whatsapp.number' => null]);
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');

        $this->assertNull(PhoneVerification::number());
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-phone-banner', false);
        $this->actingAs($owner)->get($this->dashUrl('/phone'))->assertOk()->assertDontSee('wa.me');

        PlatformSetting::put('support_whatsapp', '0100 000 0000');
        $this->assertSame('201000000000', PhoneVerification::number());
        $this->actingAs($owner)->get($this->dashUrl('/phone'))->assertOk()->assertSee('https://wa.me/201000000000?text=EGMENU%20AB2CD3', false);
    }

    public function test_after_the_module_step_the_owner_lands_on_the_confirmation_step_when_a_number_is_configured(): void
    {
        config(['whatsapp.number' => '201000000000']);
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');
        $owner->forceFill(['last_login_at' => null])->save();

        $this->get(URL::temporarySignedRoute('dash.welcome', now()->addMinutes(5), ['user' => $owner->id]))
            ->assertRedirect(route('dash.modules', ['welcome' => 1]));
        $this->actingAs($owner)->post($this->dashUrl('/modules'), ['welcome' => '1', 'modules' => ['loyalty']])->assertRedirect(route('dash.phone'));
        $this->actingAs($owner)->post($this->dashUrl('/modules/skip'), ['welcome' => '1'])->assertRedirect(route('dash.phone'));
    }

    public function test_meta_verification_handshake(): void
    {
        config(['whatsapp.verify_token' => 'verify-me']);
        $this->get($this->menuUrl('webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=4242'))
            ->assertOk()->assertSee('4242');
        $this->get($this->menuUrl('webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242'))->assertForbidden();

        config(['whatsapp.verify_token' => null]);
        $this->get($this->menuUrl('webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1'))->assertForbidden();
    }

    public function test_meta_posts_need_a_valid_signature_and_a_configured_secret(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');
        $payload = $this->metaPayload('201011111111', 'EGMENU AB2CD3');

        config(['whatsapp.app_secret' => null]);
        $this->postMeta($payload)->assertForbidden();            // nothing can be verified without the secret

        config(['whatsapp.app_secret' => self::SECRET]);
        $this->postMeta($payload, null)->assertForbidden();      // no signature
        $this->postMeta($payload, 'other-secret')->assertForbidden();
        $this->assertNull($owner->fresh()->phone_verified_at);

        $this->postMeta($payload)->assertOk();
        $this->assertNotNull($owner->fresh()->phone_verified_at);
    }

    public function test_a_message_confirms_only_the_user_whose_phone_and_code_both_match(): void
    {
        config(['whatsapp.app_secret' => self::SECRET]);
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $ownerA = $this->pending($a, '01011111111', 'AB2CD3');
        $ownerB = $this->pending($b, '01022222222', 'XY7Z89');

        // B's phone with A's code: nobody is confirmed, Meta still gets 200
        $this->postMeta($this->metaPayload('201022222222', 'EGMENU AB2CD3'))->assertOk();
        // an unrelated chat message
        $this->postMeta($this->metaPayload('201022222222', 'السلام عليكم عندي سؤال'))->assertOk();
        // the right code from a stranger's number
        $this->postMeta($this->metaPayload('201099999999', 'EGMENU XY7Z89'))->assertOk();
        $this->assertNull($ownerA->fresh()->phone_verified_at);
        $this->assertNull($ownerB->fresh()->phone_verified_at);

        // B sends their own code, lower case, with extra words and an international prefix
        $this->postMeta($this->metaPayload('201022222222', 'hi, egmenu xy7z89 thanks'))->assertOk();
        $ownerB->refresh();
        $this->assertNotNull($ownerB->phone_verified_at);
        $this->assertNull($ownerB->phone_verification_code);
        $this->assertNull($ownerA->fresh()->phone_verified_at);
        $this->assertSame('AB2CD3', $ownerA->fresh()->phone_verification_code);

        // the restaurant's audit log has the line, in B's log only
        $this->assertSame(1, $this->as($b, fn () => AuditLog::query()->where('action', 'phone.verified')->count()));
        $this->assertSame(0, $this->as($a, fn () => AuditLog::query()->where('action', 'phone.verified')->count()));

        // sending again changes nothing
        $this->postMeta($this->metaPayload('201022222222', 'EGMENU XY7Z89'))->assertOk();
    }

    public function test_another_provider_posts_from_and_text_with_the_shared_secret(): void
    {
        config(['whatsapp.app_secret' => null, 'whatsapp.webhook.secret' => 'provider-secret']);
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');

        $this->postJson($this->menuUrl('webhooks/whatsapp'), ['from' => '+201011111111', 'text' => 'EGMENU AB2CD3'])->assertForbidden();
        $this->postJson($this->menuUrl('webhooks/whatsapp'), ['from' => '+201011111111', 'text' => 'EGMENU AB2CD3'], ['X-EgMenu-Secret' => 'wrong'])->assertForbidden();
        $this->assertNull($owner->fresh()->phone_verified_at);

        $this->postJson($this->menuUrl('webhooks/whatsapp'), ['from' => '+201011111111', 'text' => 'EGMENU AB2CD3'], ['X-EgMenu-Secret' => 'provider-secret'])
            ->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($owner->fresh()->phone_verified_at);
    }

    public function test_delivery_statuses_update_the_message_and_only_move_forward(): void
    {
        config(['whatsapp.app_secret' => self::SECRET]);
        $a = $this->restaurant('cafe-a');
        $message = $this->as($a, fn () => WhatsAppMessage::query()->create([
            'to' => '201011111111', 'template' => 'order_ready', 'locale' => 'ar', 'status' => 'sent', 'provider_id' => 'wamid.123',
        ]));
        $statuses = fn (string $state, array $extra = []) => ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'statuses' => [['id' => 'wamid.123', 'status' => $state, 'recipient_id' => '201011111111'] + $extra],
        ]]]]]];

        $this->postMeta($statuses('read'))->assertOk();
        $this->assertSame('read', $message->fresh()->status);
        $this->postMeta($statuses('delivered'))->assertOk();   // late and out of order: ignored
        $this->assertSame('read', $message->fresh()->status);
        $this->postMeta($statuses('failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]))->assertOk();
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('Message undeliverable', $message->fresh()->error);
        // an unknown id is ignored
        $this->postMeta(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'nope', 'status' => 'read']]]]]]]])->assertOk();
    }

    public function test_the_owner_changes_the_number_and_gets_a_new_code(): void
    {
        config(['whatsapp.number' => '201000000000']);
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');
        $this->pending($b, '01022222222', 'XY7Z89');

        $this->actingAs($owner)->post($this->dashUrl('/phone'), ['phone' => '123'])->assertSessionHasErrors('phone');
        $this->actingAs($owner)->post($this->dashUrl('/phone'), ['phone' => '01022222222'])->assertSessionHasErrors('phone'); // B's number
        $this->actingAs($owner)->post($this->dashUrl('/phone'), ['phone' => '٠١٠٣٣٣٣٣٣٣٣'])->assertRedirect(route('dash.phone'));

        $owner->refresh();
        $this->assertSame('01033333333', $owner->phone);
        $this->assertNull($owner->phone_verified_at);
        $this->assertNotSame('AB2CD3', $owner->phone_verification_code);
        $this->assertSame(6, strlen((string) $owner->phone_verification_code));
        $this->actingAs($owner)->get($this->dashUrl('/phone'))->assertOk()->assertSee('EGMENU '.$owner->phone_verification_code);
    }

    public function test_egweb_sees_the_state_and_can_confirm_by_hand(): void
    {
        config(['whatsapp.number' => '201000000000', 'whatsapp.verify_token' => 'verify-me']);
        $a = $this->restaurant('cafe-a');
        $owner = $this->pending($a, '01011111111', 'AB2CD3');
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        // the owner cannot use the admin action
        $this->actingAs($owner)->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/verify-phone"))->assertRedirect(route('admin.login'));
        $this->assertNull($owner->fresh()->phone_verified_at);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants'))->assertOk()->assertSee('01011111111')->assertSee(__('Not confirmed'));
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertSee('EGMENU AB2CD3');
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/settings'))->assertOk()
            ->assertSee(route('webhooks.whatsapp'))->assertSee('verify-me')->assertSee('+201000000000');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/verify-phone"))->assertSessionHasNoErrors();
        $this->assertNotNull($owner->fresh()->phone_verified_at);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants'))->assertOk()->assertSee(__('Phone confirmed'));

        // a user of another restaurant is not reachable through this one
        $b = $this->restaurant('cafe-b');
        $ownerB = $this->pending($b, '01022222222', 'XY7Z89');
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$ownerB->id}/verify-phone"))->assertNotFound();
    }

    public function test_codes_are_extracted_tolerantly(): void
    {
        $this->assertSame(['AB2CD3'], PhoneVerification::extractCodes('EGMENU AB2CD3'));
        $this->assertSame(['AB2CD3'], PhoneVerification::extractCodes('egmenu: ab2cd3 .'));
        $this->assertSame(['AB2CD3'], PhoneVerification::extractCodes('كود التأكيد EGMENU AB٢CD٣ شكراً'));
        $this->assertSame([], PhoneVerification::extractCodes('EGMENU'));
        $this->assertSame([], PhoneVerification::extractCodes('AB2CD34'));     // 7 chars is not a code
        $this->assertSame([], PhoneVerification::extractCodes('AB1CD0'));      // 0/1 are never in a code
        $this->assertNotSame(PhoneVerification::code(), PhoneVerification::code());
    }
}
