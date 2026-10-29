<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\GuestSignIn;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Customers\SignIn\WhatsAppSignIn;
use App\Services\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * «ادخل بواتساب»: the guest gets a 6-digit code and a wa.me link to the EgHub number, the platform's signed webhook
 * sees the message and records the SENDER's mobile, and the page's poll signs in as that mobile's guest at that
 * restaurant only. The webhook payloads are faked; nothing is sent.
 */
class GuestWhatsAppSignInTest extends TenantTestCase
{
    private const SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.number' => '201000000001', 'whatsapp.app_secret' => self::SECRET, 'whatsapp.driver' => 'log']);
    }

    /** Taps «ادخل بواتساب» on $slug: the JSON answer and this browser's secret cookie. */
    private function start(string $slug, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($this->subUrl($slug, 'me/whatsapp'));
    }

    private function secret(TestResponse $started, string $slug): string
    {
        return (string) $started->getCookie('wa_login_'.$slug)?->getValue();
    }

    private function poll(string $slug, ?string $secret): TestResponse
    {
        $this->defaultCookies = $secret === null ? [] : ['wa_login_'.$slug => $secret];

        return $this->withCredentials()->getJson($this->subUrl($slug, 'me/whatsapp/status'));
    }

    /** The guest's message as Meta delivers it to the EgHub number, signed with the app secret. */
    private function message(string $from, string $text, ?string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '9'],
            'messages' => [['from' => $from, 'id' => 'wamid.'.random_token(4), 'timestamp' => '1', 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', $this->menuUrl('webhooks/whatsapp'), [], [], [], $headers, $raw);
    }

    private function guests(Tenant $tenant): Collection
    {
        return $this->as($tenant, fn () => Customer::query()->get());
    }

    public function test_the_code_from_the_webhook_signs_in_on_the_next_poll_as_the_senders_guest_here(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $mona = $this->as($a, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Mona']));

        $started = $this->start('cafe-a')->assertOk()->assertJsonStructure(['url', 'code', 'message', 'expires_in']);
        $code = $started->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame('https://wa.me/201000000001?text='.rawurlencode(__('My sign-in code at :place is :code', ['place' => 'Restaurant cafe-a', 'code' => $code])), $started->json('url'));
        $this->assertStringContainsString('no-store', (string) $started->headers->get('Cache-Control'));
        $secret = $this->secret($started, 'cafe-a');
        $this->assertNull(GuestSignIn::query()->where('secret_hash', $secret)->first());   // only its hash is stored

        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'pending']);
        // Arabic digits and extra words are fine; the mobile is Meta's `from`, never typed in the browser
        $this->message('201012345678', 'كود دخولي على Restaurant cafe-a هو '.strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']))->assertOk();

        // another browser, or another restaurant's host, gets nothing from it
        $this->poll('cafe-a', null)->assertExactJson(['status' => 'missing']);
        $this->poll('cafe-b', $secret)->assertExactJson(['status' => 'missing']);

        $this->poll('cafe-a', $secret)->assertOk()->assertJson(['status' => 'ok', 'redirect' => $this->subUrl('cafe-a', 'me/'.$mona->card_token)])
            ->assertCookie('card_cafe-a', $mona->card_token)->assertCookieExpired('wa_login_cafe-a');
        // used once
        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'missing']);
        $this->assertCount(1, $this->guests($a));
        $this->assertCount(0, $this->guests($b));
    }

    public function test_a_new_mobile_becomes_a_new_guest_of_that_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $atB = $this->as($b, fn () => Customer::query()->create(['phone' => '01055555555', 'name' => 'At B']));

        $started = $this->start('cafe-a');
        $this->message('201055555555', $started->json('code'))->assertOk();
        $this->poll('cafe-a', $this->secret($started, 'cafe-a'))->assertJson(['status' => 'ok']);

        $new = $this->guests($a)->sole();
        $this->assertSame('01055555555', $new->phone);
        $this->assertNotSame($atB->card_token, $new->card_token);
        $this->assertSame('At B', $this->guests($b)->sole()->name);
    }

    public function test_a_wrong_expired_unsigned_or_restaurant_number_message_signs_nobody_in(): void
    {
        $this->restaurant('cafe-a');
        $started = $this->start('cafe-a');
        $code = $started->json('code');
        $secret = $this->secret($started, 'cafe-a');

        $this->message('201012345678', 'كود دخولي هو '.($code === '999999' ? '999998' : '999999'))->assertOk();
        $this->message('201012345678', $code, null)->assertForbidden();
        $this->message('201012345678', $code, 'other-secret')->assertForbidden();
        $this->message('+44 7700 900123', $code)->assertOk();                          // not an Egyptian mobile
        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'pending']);

        $this->travelTo(now()->addMinutes(WhatsAppSignIn::MINUTES)->addSecond());
        $this->message('201012345678', $code)->assertOk();
        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'expired']);
        $this->assertNull(GuestSignIn::query()->value('phone'));
    }

    public function test_starts_are_limited_per_ip_and_confirmations_per_mobile(): void
    {
        $this->restaurant('cafe-a');
        for ($i = 0; $i < WhatsAppSignIn::IP_STARTS; $i++) {
            $this->start('cafe-a')->assertOk();
        }
        $this->start('cafe-a')->assertStatus(429)->assertJson(['message' => __('Too many tries. Please try again in a few minutes.')]);
        $this->start('cafe-a', '10.0.0.2')->assertOk();

        $codes = GuestSignIn::query()->pluck('code')->all();
        foreach ($codes as $code) {
            $this->message('201012345678', $code)->assertOk();
        }
        $this->assertSame(WhatsAppSignIn::PHONE_TRIES, GuestSignIn::query()->whereNotNull('verified_at')->count());
    }

    public function test_a_message_full_of_numbers_or_a_run_of_wrong_codes_cannot_guess_a_guests_code(): void
    {
        $this->restaurant('cafe-a');
        $started = $this->start('cafe-a');
        $code = $started->json('code');
        $secret = $this->secret($started, 'cafe-a');
        $wrong = fn (int $i): string => (string) ((((int) $code) + $i) % 900000 + 100000);

        // only the last number in a message is tried, so a list of guesses holding the right one gets nowhere
        $this->message('201012345678', $code.' '.implode(' ', array_map($wrong, range(1, 50))))->assertOk();
        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'pending']);

        // wrong codes use up the mobile's tries, after which even the right code is refused
        for ($i = 1; $i < WhatsAppSignIn::PHONE_TRIES; $i++) {
            $this->message('201012345678', $wrong(100 + $i))->assertOk();
        }
        $this->message('201012345678', $code)->assertOk();
        $this->poll('cafe-a', $secret)->assertExactJson(['status' => 'pending']);

        // another mobile still can, and digits in the place's name before the code are ignored
        $this->message('201099999999', 'كود دخولي على Cafe 123456 هو '.$code)->assertOk();
        $this->poll('cafe-a', $secret)->assertJson(['status' => 'ok']);
    }

    public function test_the_button_shows_only_with_the_platform_number_and_a_verifiable_webhook(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-wa-sign-in', false)
            ->assertSee($this->subUrl('cafe-a', 'me/whatsapp'), false);

        // the support number alone (maybe a person's phone) is not enough, nor is a number without a verifiable webhook
        foreach ([['whatsapp.number' => null], ['whatsapp.app_secret' => null, 'whatsapp.webhook.secret' => null], ['whatsapp.guest_sign_in' => false]] as $off) {
            config(['whatsapp.number' => '201000000001', 'whatsapp.app_secret' => self::SECRET, 'whatsapp.guest_sign_in' => true]);
            config($off);
            $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-wa-sign-in', false)->assertSee('data-account-verify', false);
            $this->start('cafe-a')->assertNotFound();
            $this->poll('cafe-a', null)->assertNotFound();
        }
    }

    public function test_a_restaurant_asking_for_a_proven_mobile_takes_delivery_only_from_a_guest_signed_in_with_it(): void
    {
        config(['whatsapp.guest_sign_in' => true]);
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $zone = $this->as($a, function () {
            Settings::put(['online_delivery' => '1', 'online_verify_phone' => 'delivery']);

            return DeliveryZone::query()->create(['branch_id' => Branch::query()->first()->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 0]);
        });
        $order = fn (string $type, string $phone = '01012345678') => $this->withCredentials()->postJson($this->subUrl('cafe-a', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]], 'customer_name' => 'Mona',
            'customer_phone' => $phone, 'type' => $type, 'zone_id' => $zone->id, 'address' => '9 Road 233',
        ]);

        // a typed mobile: the delivery waits for the guest to sign in with it; a pickup goes as before, and (a new
        // guest) hands this browser the guest's card, which is no proof of the mobile
        $order('delivery')->assertStatus(422)->assertJson(['sign_in_url' => $this->subUrl('cafe-a', 'me')]);
        $this->assertSame(0, $this->as($a, fn () => Order::query()->count()));
        $this->defaultCookies = [];
        $typedCard = $order('takeaway')->assertOk()->getCookie('card_cafe-a')->getValue();

        // the owner of the mobile signs in with WhatsApp in their own browser: proven there, and only there
        $this->defaultCookies = [];
        $started = $this->start('cafe-a');
        $this->message('201012345678', $started->json('code'))->assertOk();
        $signedIn = $this->poll('cafe-a', $this->secret($started, 'cafe-a'))->assertJson(['status' => 'ok']);
        $this->assertNotNull($this->guests($a)->firstWhere('phone', '01012345678')->phone_verified_at);
        $this->defaultCookies = ['card_cafe-a' => $typedCard];
        $order('delivery')->assertStatus(422);

        $this->defaultCookies = ['card_cafe-a' => $signedIn->getCookie('card_cafe-a')->getValue(), 'phone_ok_cafe-a' => $signedIn->getCookie('phone_ok_cafe-a')->getValue()];
        $order('delivery', '01099999999')->assertStatus(422);
        $order('delivery')->assertOk();

        // with no way to send a code the setting holds nothing
        config(['whatsapp.number' => '']);
        $this->defaultCookies = [];
        $order('delivery', '01077777777')->assertOk();
    }
}
