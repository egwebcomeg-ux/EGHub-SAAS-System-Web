<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Notifications\GuestAccountEmail;
use App\Services\Customers\ClaimLinks;
use App\Services\Customers\SignIn\PhoneCodeSignIn;
use App\Services\Sms\SmsDriver;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The guest's ways into «حسابي» added with guest accounts v2, each per restaurant: the code to the mobile (the
 * restaurant's own WhatsApp number, SMS, or the email that guest has), «ادخل بالإيميل» (link + code), email +
 * password with «نسيت الباسورد؟», the claim link of «ادعي عملاءك», «أكّد إيميلك» after an order, and the returning
 * guest's card on the menu.
 */
class GuestSignInWaysTest extends TenantTestCase
{
    private const PHONE = '01012345678';

    /** @var list<array{phone:string, text:string}> */
    private array $sms = [];

    private function guest(Tenant $tenant, array $attributes = []): Customer
    {
        return $this->as($tenant, fn () => Customer::query()->create($attributes + ['phone' => self::PHONE, 'name' => 'Mona Adel']));
    }

    /** A provider that keeps what it would have sent. */
    private function fakeSms(): void
    {
        $test = $this;
        app()->instance(SmsDriver::class, new class($test) implements SmsDriver
        {
            public function __construct(private $test) {}

            public function send(string $phone, string $text): array
            {
                $this->test->recordSms($phone, $text);

                return ['ok' => true, 'error' => null];
            }
        });
    }

    public function recordSms(string $phone, string $text): void
    {
        $this->sms[] = ['phone' => $phone, 'text' => $text];
    }

    private function lastSmsCode(): string
    {
        preg_match('/(\d{6})/', end($this->sms)['text'], $m);

        return $m[1];
    }

    /** The last email of this kind to $email (its url and code). */
    private function mailTo(string $email, string $kind = GuestAccountEmail::SIGN_IN): ?GuestAccountEmail
    {
        $found = null;
        Notification::assertSentOnDemand(GuestAccountEmail::class, function (GuestAccountEmail $n, array $channels, object $notifiable) use ($email, $kind, &$found) {
            if (($notifiable->routes['mail'] ?? null) === $email && $n->kind === $kind) {
                $found = $n;
            }

            return true;
        });

        return $found;
    }

    private function phoneStart(string $slug, string $phone = self::PHONE, ?string $channel = null, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($this->subUrl($slug, 'me/phone-code'), ['phone' => $phone, 'channel' => $channel]);
    }

    private function phoneVerify(string $slug, string $code, string $phone = self::PHONE, array $cookies = []): TestResponse
    {
        $this->defaultCookies = $cookies;

        return $this->withCredentials()->postJson($this->subUrl($slug, 'me/phone-code/verify'), ['phone' => $phone, 'code' => $code]);
    }

    // ---- the code to the mobile ----

    public function test_a_code_by_sms_opens_the_imported_guest_with_their_saved_address(): void
    {
        $this->fakeSms();
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a, ['source' => 'import', 'addresses' => [['label' => '', 'zone_id' => null, 'text' => '9 Road 233, Maadi']]]);

        $this->phoneStart('cafe-a')->assertOk()->assertJson(['status' => 'sent', 'channel' => 'sms', 'resend_in' => 60])
            ->assertJsonFragment(['message' => __('We sent a code by SMS to :phone.', ['phone' => '010•••••678'])]);
        $this->assertSame(self::PHONE, $this->sms[0]['phone']);
        $this->assertStringNotContainsStringIgnoringCase('password', $this->sms[0]['text']);

        $done = $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertOk()->assertJson(['ok' => true])->assertCookie('card_cafe-a', $mona->card_token);
        $this->assertStringContainsString('no-store', (string) $done->headers->get('Cache-Control'));
        // the same guest, not a new one: their address is on their account
        $this->assertSame(1, $this->as($a, fn () => Customer::query()->count()));
        $this->withCookie('card_cafe-a', $mona->card_token)->get($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertOk()->assertSee('9 Road 233, Maadi');
        // and the code is used up
        $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertStatus(422)->assertCookieMissing('card_cafe-a');
    }

    public function test_a_new_mobile_becomes_a_new_guest_and_the_resend_waits_a_minute(): void
    {
        $this->fakeSms();
        $a = $this->restaurant('cafe-a');
        $this->phoneStart('cafe-a')->assertJson(['status' => 'sent']);
        $this->phoneStart('cafe-a')->assertJson(['status' => 'sent'])->assertJsonPath('resend_in', fn ($n) => $n > 0 && $n <= 60);
        $this->assertCount(1, $this->sms);
        $this->travel(61)->seconds();
        $this->phoneStart('cafe-a')->assertJson(['status' => 'sent']);
        $this->assertCount(2, $this->sms);

        $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertOk();
        $this->assertSame([self::PHONE], $this->as($a, fn () => Customer::query()->pluck('phone')->all()));
    }

    public function test_wrong_expired_and_too_many_codes_open_nothing(): void
    {
        $this->fakeSms();
        $this->restaurant('cafe-a');
        $this->phoneStart('cafe-a');
        $code = $this->lastSmsCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < PhoneCodeSignIn::TRIES; $i++) {
            $this->phoneVerify('cafe-a', $wrong)->assertStatus(422);
        }
        // five wrong codes: even the right one no longer works
        $this->phoneVerify('cafe-a', $code)->assertStatus(422)->assertCookieMissing('card_cafe-a');

        $this->travel(61)->seconds();
        $this->phoneStart('cafe-a');
        $this->travel(PhoneCodeSignIn::MINUTES * 60 + 1)->seconds();
        $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertStatus(422);
    }

    public function test_codes_are_limited_per_mobile_and_per_ip(): void
    {
        $this->fakeSms();
        $this->restaurant('cafe-a');
        for ($i = 0; $i < PhoneCodeSignIn::PHONE_PER_HOUR; $i++) {
            $this->phoneStart('cafe-a', self::PHONE, null, '10.0.0.'.$i)->assertJson(['status' => 'sent']);
            $this->travel(61)->seconds();
        }
        $this->phoneStart('cafe-a', self::PHONE, null, '10.0.1.1')->assertStatus(429);

        for ($i = 0; $i < PhoneCodeSignIn::IP_PER_HOUR; $i++) {
            $this->phoneStart('cafe-a', '0111111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), null, '10.9.9.9');
        }
        $this->phoneStart('cafe-a', '01222222222', null, '10.9.9.9')->assertStatus(429);
    }

    public function test_a_code_of_one_restaurant_never_opens_another(): void
    {
        $this->fakeSms();
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->guest($a);
        $other = $this->guest($b, ['name' => 'Mona at B']);
        $this->phoneStart('cafe-a');

        $this->phoneVerify('cafe-b', $this->lastSmsCode())->assertStatus(422)->assertCookieMissing('card_cafe-b');
        $this->assertNull($this->as($b, fn () => GuestSignIn::query()->where('customer_id', $other->id)->first()));
        $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertOk();
    }

    public function test_the_restaurants_own_whatsapp_number_sends_first_and_takes_no_eghub_credit(): void
    {
        $this->fakeSms();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => WhatsAppConnection::query()->create([
            'waba_id' => '111', 'phone_number_id' => '222', 'access_token' => 'tok', 'webhook_key' => random_token(16), 'verify_token' => random_token(20),
            'status' => 'connected', 'templates' => ['egmenu_sign_in_code' => ['ar' => 'approved']],
        ]));

        $this->phoneStart('cafe-a')->assertJson(['status' => 'sent', 'channel' => 'whatsapp'])
            ->assertJsonFragment(['channel' => 'sms']); // «ابعته بطريقة تانية»: SMS is one of the other ways
        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return str_ends_with($request->url(), '/222/messages');
        });
        $this->assertSame('egmenu_sign_in_code', $sent['template']['name']);
        $code = $sent['template']['components'][0]['parameters'][0]['text'];
        $this->assertSame($code, $sent['template']['components'][1]['parameters'][0]['text']); // the copy-code button
        $this->assertCount(0, $this->sms);
        $this->assertSame(0, $this->as($a, fn () => WhatsAppMessage::query()->count()));
        $this->assertSame(['whatsapp' => ['count' => 1, 'cost' => 0.0]], $this->as($a, fn () => PhoneCodeSignIn::usage()));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers'))->assertOk()->assertSee('data-code-usage-channel="whatsapp"', false);

        // «ابعته بطريقة تانية»: the SMS after the minute
        $this->travel(61)->seconds();
        $this->phoneStart('cafe-a', self::PHONE, 'sms')->assertJson(['channel' => 'sms']);
        $this->phoneVerify('cafe-a', $this->lastSmsCode())->assertOk();
    }

    public function test_without_whatsapp_or_sms_the_code_goes_to_the_email_the_guest_has(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a, ['email' => 'mona@example.com']);

        $this->phoneStart('cafe-a')->assertJson(['status' => 'sent', 'channel' => 'email'])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'mo•••@example.com'));
        $mail = $this->mailTo('mona@example.com');
        $this->assertNotNull($mail);
        $this->phoneVerify('cafe-a', (string) $mail->code)->assertOk()->assertCookie('card_cafe-a', $mona->card_token);
    }

    public function test_without_any_way_to_send_the_sheet_says_so_and_offers_the_others(): void
    {
        Notification::fake();
        $this->restaurant('cafe-a');
        $this->guest($this->restaurant('cafe-a2'));

        $this->phoneStart('cafe-a')->assertOk()->assertJson(['status' => 'choose'])
            ->assertJsonFragment(['message' => __('We cannot send a code to this mobile here yet. Sign in with your email, or with your mobile + a previous order number.')])
            ->assertJsonFragment(['channel' => 'order'])->assertJsonFragment(['channel' => 'email_sign_in']);
        Notification::assertNothingSent();
    }

    // ---- «ادخل بالإيميل» ----

    public function test_the_email_link_signs_in_once_and_answers_the_same_for_any_email(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a, ['email' => 'mona@example.com', 'email_verified_at' => now()]);

        $known = $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'Mona@Example.com'])->assertOk()->json();
        $unknown = $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'nobody@example.com'])->assertOk()->json();
        $this->assertSame($known, $unknown);

        $mail = $this->mailTo('mona@example.com');
        $this->assertStringStartsWith($this->subUrl('cafe-a', 'me/email/'), $mail->url); // the restaurant's own host
        $rendered = $mail->toMail(new AnonymousNotifiable);
        $this->assertSame('Restaurant cafe-a', $rendered->from[1]);
        $this->assertStringContainsString((string) $mail->code, $rendered->subject);

        // the link opens a page with a button (a mail scanner opening it uses nothing up), the button signs in
        $this->get($mail->url)->assertOk()->assertSee('data-email-link-form', false)->assertCookieMissing('card_cafe-a');
        $this->post($mail->url)->assertRedirect($this->subUrl('cafe-a', "me/{$mona->card_token}"))->assertCookie('card_cafe-a', $mona->card_token);
        $this->post($mail->url)->assertRedirect($this->subUrl('cafe-a', 'me'))->assertCookieMissing('card_cafe-a');
        $this->get($mail->url)->assertStatus(410);
    }

    public function test_the_email_code_works_once_and_expires(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'new@example.com']);
        $mail = $this->mailTo('new@example.com');

        $this->postJson($this->subUrl('cafe-a', 'me/email/code'), ['email' => 'new@example.com', 'code' => $mail->code])->assertOk()
            ->assertJson(['redirect' => $this->subUrl('cafe-a', 'me/phone')]); // a new guest is asked for the mobile
        $guest = $this->as($a, fn () => Customer::query()->where('email', 'new@example.com')->sole());
        $this->assertNotNull($guest->email_verified_at);
        $this->postJson($this->subUrl('cafe-a', 'me/email/code'), ['email' => 'new@example.com', 'code' => $mail->code])->assertStatus(422);

        $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'late@example.com']);
        $late = $this->mailTo('late@example.com');
        $this->travel(16)->minutes();
        $this->postJson($this->subUrl('cafe-a', 'me/email/code'), ['email' => 'late@example.com', 'code' => $late->code])->assertStatus(422);
        $this->post($late->url)->assertRedirect($this->subUrl('cafe-a', 'me'))->assertCookieMissing('card_cafe-a');
    }

    public function test_email_links_are_limited_per_email_and_per_ip(): void
    {
        Notification::fake();
        $this->restaurant('cafe-a');
        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$i])->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'mona@example.com'])->assertOk();
        }
        $count = 0;
        Notification::assertSentOnDemand(GuestAccountEmail::class, function () use (&$count) {
            $count++;

            return true;
        });
        $this->assertSame(4, $count); // the same answer, but only 4 emails an hour to one address

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => "g$i@example.com"]);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'g99@example.com'])->assertStatus(429);
    }

    public function test_an_email_link_opens_only_its_own_restaurant(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->guest($b, ['email' => 'mona@example.com', 'email_verified_at' => now()]);
        $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'mona@example.com']);
        $mail = $this->mailTo('mona@example.com');

        $token = basename($mail->url);
        $this->post($this->subUrl('cafe-b', 'me/email/'.$token))->assertRedirect($this->subUrl('cafe-b', 'me'))->assertCookieMissing('card_cafe-b');
        // at cafe-a the same email is a new guest there, never cafe-b's
        $this->post($mail->url)->assertCookie('card_cafe-a');
        $this->assertSame(1, $this->as($a, fn () => Customer::query()->count()));
        $this->assertSame('Mona Adel', $this->as($b, fn () => Customer::query()->sole()->name));
    }

    // ---- email + password ----

    public function test_sign_up_sign_in_and_a_wrong_password(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->post($this->subUrl('cafe-a', 'me/register'), ['name' => 'Sara', 'phone' => '01099999999', 'email' => 'Sara@Example.com',
            'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1'])->assertRedirect()->assertCookie('card_cafe-a');
        $sara = $this->as($a, fn () => Customer::query()->where('email', 'sara@example.com')->sole());
        $this->assertSame('01099999999', $sara->phone);
        $this->assertNotSame('long-enough-1', $sara->getRawOriginal('password'));

        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'sara@example.com', 'password' => 'long-enough-1'])
            ->assertRedirect($this->subUrl('cafe-a', "me/{$sara->card_token}"))->assertCookie('card_cafe-a', $sara->card_token);
        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'sara@example.com', 'password' => 'wrong-one-123'])
            ->assertSessionHasErrors(['email' => __('The email or the password is not right.')], errorBag: 'login')->assertCookieMissing('card_cafe-a');
        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'nobody@example.com', 'password' => 'wrong-one-123'])
            ->assertSessionHasErrors(['email' => __('The email or the password is not right.')], errorBag: 'login');
        // the same email at another restaurant is another account, with its own password
        $this->restaurant('cafe-b');
        $this->post($this->subUrl('cafe-b', 'me/login'), ['email' => 'sara@example.com', 'password' => 'long-enough-1'])->assertCookieMissing('card_cafe-b');
    }

    public function test_sign_up_with_a_mobile_another_guest_has_never_takes_it_over(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a, ['source' => 'import', 'addresses' => [['label' => '', 'zone_id' => null, 'text' => 'Mona street']]]);

        $response = $this->post($this->subUrl('cafe-a', 'me/register'), ['name' => 'Someone', 'phone' => self::PHONE, 'email' => 'x@example.com',
            'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1']);
        $new = $this->as($a, fn () => Customer::query()->where('email', 'x@example.com')->sole());
        $response->assertCookie('card_cafe-a', $new->card_token);
        $this->assertSame(['', self::PHONE], [$new->phone, $new->pending_phone]);
        $this->assertSame('Mona Adel', $mona->fresh()->name);
        // the new account sees nothing of Mona's, and is asked to prove the mobile
        $this->withCookie('card_cafe-a', $new->card_token)->get($this->subUrl('cafe-a', "me/{$new->card_token}"))
            ->assertOk()->assertDontSee('Mona street')->assertSee('data-account-prove-phone', false);
        $this->withCookie('card_cafe-a', $new->card_token)->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->assertJsonPath('guest.addresses', []);

        // proving it (a code to the mobile) joins the two: Mona keeps her address and gets the email + password
        $this->fakeSms();
        $this->phoneStart('cafe-a');
        $this->phoneVerify('cafe-a', $this->lastSmsCode(), self::PHONE, ['card_cafe-a' => $new->card_token])->assertCookie('card_cafe-a', $mona->card_token);
        $this->assertNull($this->as($a, fn () => Customer::query()->find($new->id)));
        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'x@example.com', 'password' => 'long-enough-1'])->assertCookie('card_cafe-a', $mona->card_token);
    }

    public function test_forgot_password_answers_the_same_and_the_reset_link_works_once(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $sara = $this->guest($a, ['phone' => '01099999999', 'email' => 'sara@example.com', 'password' => 'old-password-1']);

        $known = $this->post($this->subUrl('cafe-a', 'me/forgot'), ['email' => 'sara@example.com'])->assertSessionHas('ok');
        $unknown = $this->post($this->subUrl('cafe-a', 'me/forgot'), ['email' => 'nobody@example.com'])->assertSessionHas('ok');
        $this->assertSame($known->getSession()->get('ok'), $unknown->getSession()->get('ok'));
        $this->assertNull($this->mailTo('nobody@example.com', GuestAccountEmail::RESET));
        $mail = $this->mailTo('sara@example.com', GuestAccountEmail::RESET);

        $this->get($mail->url)->assertOk()->assertSee('data-reset-form', false);
        $this->post($mail->url, ['password' => 'new-password-2', 'password_confirmation' => 'new-password-2'])->assertCookie('card_cafe-a', $sara->card_token);
        $this->post($mail->url, ['password' => 'other-password-3', 'password_confirmation' => 'other-password-3'])->assertCookieMissing('card_cafe-a');
        $this->get($mail->url)->assertStatus(410);
        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'sara@example.com', 'password' => 'new-password-2'])->assertCookie('card_cafe-a');
        $this->assertNotNull($sara->fresh()->email_verified_at);

        $this->post($this->subUrl('cafe-a', 'me/forgot'), ['email' => 'sara@example.com']);
        $late = $this->mailTo('sara@example.com', GuestAccountEmail::RESET);
        $this->travel(61)->minutes();
        $this->get($late->url)->assertStatus(410);
    }

    // ---- «ادعي عملاءك» ----

    public function test_a_claim_link_greets_the_guest_with_their_address_and_works_once(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a, ['source' => 'import', 'addresses' => [['label' => '', 'zone_id' => null, 'text' => '9 Road 233']]]);
        $url = $this->as($a, fn () => app(ClaimLinks::class)->issue($mona));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $token = basename($path);

        $this->get($this->subUrl('cafe-a', 'me/claim/'.$token))->assertOk()->assertSee('Mona')->assertSee('9 Road 233')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        // another restaurant does not know the link
        $this->restaurant('cafe-b');
        $this->get($this->subUrl('cafe-b', 'me/claim/'.$token))->assertStatus(410)->assertDontSee('9 Road 233');

        $this->post($this->subUrl('cafe-a', 'me/claim/'.$token), ['email' => 'mona@example.com', 'password' => 'mona-pass-1', 'password_confirmation' => 'mona-pass-1'])
            ->assertCookie('card_cafe-a', $mona->card_token);
        $this->get($this->subUrl('cafe-a', 'me/claim/'.$token))->assertStatus(410);
        $this->post($this->subUrl('cafe-a', 'me/login'), ['email' => 'mona@example.com', 'password' => 'mona-pass-1'])->assertCookie('card_cafe-a', $mona->card_token);

        $late = $this->as($a, fn () => app(ClaimLinks::class)->issue($mona, 1));
        $this->travel(2)->days();
        $this->get($this->subUrl('cafe-a', 'me/claim/'.basename((string) parse_url($late, PHP_URL_PATH))))->assertStatus(410);
    }

    // ---- «أكّد إيميلك» after an order ----

    private function onlineOrder(Tenant $tenant, array $extra = [], array $cookies = []): TestResponse
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->first());
        $this->defaultCookies = $cookies;

        return $this->postJson($this->subUrl($tenant->id, 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]], 'type' => 'takeaway',
            'customer_name' => 'Mona', 'customer_phone' => self::PHONE,
        ]);
    }

    public function test_a_new_guest_confirms_the_email_typed_at_checkout(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->onlineOrder($a, ['customer_email' => 'Mona@Example.com'])->assertOk();
        $guest = $this->as($a, fn () => Customer::query()->sole());
        $this->assertSame([null, 'mona@example.com'], [$guest->email, $guest->pending_email]);
        $mail = $this->mailTo('mona@example.com', GuestAccountEmail::CONFIRM);

        // the guest this very order created: confirmed even from another device
        $this->post(str_replace('/me/email/confirm/', '/me/email/confirm/', $mail->url))->assertRedirect()->assertCookie('card_cafe-a', $guest->card_token);
        $this->assertSame('mona@example.com', $guest->fresh()->email);
        $this->assertNotNull($guest->fresh()->email_verified_at);
    }

    public function test_an_email_typed_next_to_an_existing_guests_mobile_stays_pending_until_the_mobile_is_proven(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $mona = $this->guest($a, ['source' => 'import', 'addresses' => [['label' => '', 'zone_id' => null, 'text' => 'Mona street']]]);

        // someone types Mona's mobile and their own email
        $this->onlineOrder($a, ['customer_email' => 'attacker@example.com'])->assertOk()->assertCookieMissing('card_cafe-a');
        $this->assertSame([null, 'attacker@example.com'], [$mona->fresh()->email, $mona->fresh()->pending_email]);
        $mail = $this->mailTo('attacker@example.com', GuestAccountEmail::CONFIRM);
        $this->post($mail->url)->assertRedirect($this->subUrl('cafe-a', 'me'))->assertCookieMissing('card_cafe-a');
        $this->assertNull($mona->fresh()->email);
        // an emailed sign-in link to that address opens a NEW guest, never Mona
        $this->postJson($this->subUrl('cafe-a', 'me/email'), ['email' => 'attacker@example.com']);
        $this->post($this->mailTo('attacker@example.com')->url)->assertCookie('card_cafe-a');
        $this->assertNotSame($mona->card_token, $this->as($a, fn () => Customer::query()->where('email', 'attacker@example.com')->value('card_token')));
        $this->assertSame(['guest' => null], $this->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->json());

        // Mona herself, signed in on her phone, confirms it there
        $this->defaultCookies = ['card_cafe-a' => $mona->card_token];
        $this->as($a, fn () => Customer::query()->where('email', 'attacker@example.com')->delete());
        $this->post($mail->url)->assertRedirect($this->subUrl('cafe-a', "me/{$mona->card_token}"));
        $this->assertSame('attacker@example.com', $mona->fresh()->email);
    }

    // ---- the returning guest on the menu ----

    public function test_the_returning_guest_summary_is_private_per_restaurant_and_skips_what_is_gone(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->guest($a);
        [$latte, $cake] = $this->as($a, function () use ($mona) {
            $cat = Category::query()->firstOrCreate(['name' => 'Coffee']);
            $latte = MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Latte', 'price' => 65]);
            $cake = MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Cake', 'price' => 40, 'is_sold_out' => true]);
            $order = Order::query()->create(['branch_id' => Branch::query()->value('id'), 'number' => 7, 'type' => 'takeaway', 'status' => 'served',
                'source' => 'online', 'subtotal' => 170, 'total' => 170, 'tracking_token' => random_token(12), 'customer_id' => $mona->id, 'customer_phone' => self::PHONE]);
            OrderItem::query()->create(['order_id' => $order->id, 'menu_item_id' => $latte->id, 'name' => 'Latte', 'unit_price' => 65, 'quantity' => 2, 'line_total' => 130]);
            OrderItem::query()->create(['order_id' => $order->id, 'menu_item_id' => $cake->id, 'name' => 'Cake', 'unit_price' => 40, 'quantity' => 1, 'line_total' => 40]);

            return [$latte, $cake];
        });

        $summary = $this->withCookie('card_cafe-a', $mona->card_token)->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->assertOk()
            ->assertJsonPath('guest.name', 'Mona')->assertJsonPath('guest.last.number', 7)
            ->assertJsonPath('guest.last.lines.0.item_id', $latte->id)->assertJsonPath('guest.last.lines.0.qty', 2)
            ->assertJsonPath('guest.last.skipped', 1)->assertJsonPath('guest.last.skippedNames', ['Cake'])
            ->assertJsonPath('guest.favourites', [$latte->id]);
        $this->assertStringContainsString('no-store', (string) $summary->headers->get('Cache-Control'));
        $this->assertCount(1, $summary->json('guest.last.lines'));

        // no cookie, another restaurant's cookie or a made-up one: nobody
        $this->defaultCookies = [];
        $this->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->assertExactJson(['guest' => null]);
        $this->restaurant('cafe-b');
        $this->withCookie('card_cafe-b', $mona->card_token)->withCredentials()->getJson($this->subUrl('cafe-b', 'me/summary'))->assertExactJson(['guest' => null]);
        $this->withCookie('card_cafe-a', str_repeat('a', 24))->withCredentials()->getJson($this->subUrl('cafe-a', 'me/summary'))->assertExactJson(['guest' => null]);

        // the menu itself carries no guest data for a visitor without the cookie (the copy the page cache keeps)
        $this->defaultCookies = [];
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-guest-welcome', false)
            ->assertDontSee('"summaryUrl":"', false)->assertDontSee('Mona Adel');
    }
}
