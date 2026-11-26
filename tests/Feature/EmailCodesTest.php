<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\AccountCode;
use App\Notifications\NewSignIn;
use App\Services\Auth\EmailCode;
use App\Services\Auth\LoginAlert;
use App\Services\Auth\Totp;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/**
 * Codes by email for restaurant staff (App\Services\Auth\EmailCode): two-factor by email, an emailed code instead
 * of the app, confirming the account's email, "is it really you?" before sensitive actions, and the email about a
 * sign-in from a new device.
 */
class EmailCodesTest extends TenantTestCase
{
    private const LOGIN = ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** The last code of $purpose emailed to $email. */
    private function lastCode(string $purpose, string $email = 'owner@cafe-a.test'): string
    {
        $code = null;
        Notification::assertSentOnDemand(AccountCode::class, function (AccountCode $notification, array $channels, object $notifiable) use (&$code, $purpose, $email) {
            if ($notification->purpose !== $purpose || $notifiable->routes['mail'] !== $email) {
                return false;
            }
            $code = $notification->code;

            return true;
        });

        return (string) $code;
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_an_owner_turns_on_two_factor_by_email_and_signs_in_with_the_emailed_code(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/email'))->assertRedirect(route('dash.security'));
        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-two-factor-email-confirm', false);
        $code = $this->lastCode(EmailCode::ENABLE);
        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/email/confirm'), ['code' => $this->wrong($code)])->assertSessionHasErrors('email_code');
        $this->assertFalse($owner->fresh()->hasTwoFactorEnabled());

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/email/confirm'), ['code' => $code])->assertRedirect(route('dash.security'));
        $owner->refresh();
        $this->assertTrue($owner->hasEmailTwoFactor());
        $this->assertNotNull($owner->email_verified_at);
        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-two-factor-email-on', false);
        $this->post($this->dashUrl('/logout'));

        // the password alone stops at the challenge, which has already emailed the code
        $this->post($this->dashUrl('/login'), self::LOGIN)->assertRedirect(route('dash.two-factor'));
        $this->assertGuest('web');
        $this->get($this->dashUrl('/two-factor'))->assertOk()->assertSee('data-two-factor-by-email', false);
        $login = $this->lastCode(EmailCode::LOGIN);
        $this->post($this->dashUrl('/two-factor'), ['code' => $this->wrong($login)])->assertSessionHasErrors('code');
        $this->post($this->dashUrl('/two-factor'), ['code' => $login])->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->post($this->dashUrl('/logout'));

        // a code works once
        $this->post($this->dashUrl('/login'), self::LOGIN);
        $this->post($this->dashUrl('/two-factor'), ['code' => $login])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_an_app_user_may_ask_for_an_emailed_code_but_a_platform_admin_may_not(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $owner->startTwoFactorSetup(Totp::secret());
        $owner->confirmTwoFactor();

        $this->post($this->dashUrl('/login'), self::LOGIN)->assertRedirect(route('dash.two-factor'));
        Notification::assertNothingSent();
        $this->get($this->dashUrl('/two-factor'))->assertOk()->assertSee('data-two-factor-email', false);
        $this->post($this->dashUrl('/two-factor/email'))->assertSessionHasNoErrors();
        // a second email within the minute is refused (the first code stays valid)
        $this->post($this->dashUrl('/two-factor/email'))->assertSessionHasErrors('code');
        $this->post($this->dashUrl('/two-factor'), ['code' => $this->lastCode(EmailCode::LOGIN)])->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');

        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@eghub.test', 'password' => 'Secret-pass-123']);
        $admin->startTwoFactorSetup(Totp::secret());
        $admin->confirmTwoFactor();
        $this->post($this->adminUrl('/login'), ['email' => 'admin@eghub.test', 'password' => 'Secret-pass-123'])->assertRedirect(route('admin.two-factor'));
        $this->get($this->adminUrl('/two-factor'))->assertOk()->assertDontSee('data-two-factor-email', false);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/security'))->assertOk()->assertDontSee('data-two-factor-email-start', false);
    }

    public function test_an_emailed_code_dies_after_five_wrong_tries_and_after_ten_minutes(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));

        EmailCode::send($owner, EmailCode::VERIFY);
        $code = $this->lastCode(EmailCode::VERIFY);
        for ($i = 0; $i < EmailCode::TRIES; $i++) {
            $this->assertFalse(EmailCode::check($owner, EmailCode::VERIFY, $this->wrong($code)));
        }
        $this->assertFalse(EmailCode::check($owner, EmailCode::VERIFY, $code));

        $this->travel(EmailCode::RESEND_SECONDS + 1)->seconds();
        EmailCode::send($owner, EmailCode::VERIFY);
        $code = $this->lastCode(EmailCode::VERIFY);
        $this->travel(EmailCode::MINUTES + 1)->minutes();
        $this->assertFalse(EmailCode::check($owner, EmailCode::VERIFY, $code));

        // a code is bound to its purpose
        $this->travel(EmailCode::RESEND_SECONDS + 1)->seconds();
        EmailCode::send($owner, EmailCode::VERIFY);
        $this->assertFalse(EmailCode::check($owner, EmailCode::LOGIN, $this->lastCode(EmailCode::VERIFY)));
        $this->assertTrue(EmailCode::check($owner, EmailCode::VERIFY, '  '.$this->lastCode(EmailCode::VERIFY)));
    }

    public function test_an_owner_confirms_the_email_from_the_banner_and_a_new_email_is_unconfirmed_again(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $this->assertNull($owner->email_verified_at);

        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertSee('data-email-banner', false);
        $this->actingAs($owner)->get($this->dashUrl('/email'))->assertOk()->assertSee('data-email-verify', false);
        $code = $this->lastCode(EmailCode::VERIFY);
        $this->actingAs($owner)->post($this->dashUrl('/email'), ['code' => $this->wrong($code)])->assertSessionHasErrors('code');
        $this->actingAs($owner)->post($this->dashUrl('/email'), ['code' => $code])->assertRedirect(route('dash.home'));
        $this->assertNotNull($owner->fresh()->email_verified_at);

        $this->actingAs($owner->fresh())->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-email-banner', false);
        $this->actingAs($owner->fresh())->get($this->dashUrl('/email'))->assertRedirect(route('dash.home'));

        $owner->fresh()->forceFill(['email' => 'new-owner@cafe-a.test'])->save();
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_a_sensitive_action_asks_for_the_emailed_code_first(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertRedirect(route('dash.confirm'));
        $this->actingAs($owner)->get($this->dashUrl('/confirm'))->assertOk()->assertSee('data-confirm-identity', false);
        $code = $this->lastCode(EmailCode::CONFIRM);
        $this->actingAs($owner)->post($this->dashUrl('/confirm'), ['code' => $this->wrong($code)])->assertSessionHasErrors('code');
        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertRedirect(route('dash.confirm'));

        // the right code goes back to the export, which now opens, and is not asked again within 15 minutes
        $this->actingAs($owner)->post($this->dashUrl('/confirm'), ['code' => $code])->assertRedirect($this->dashUrl('/customers/export'));
        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertOk();
        $this->travel(16)->minutes();
        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertRedirect(route('dash.confirm'));
    }

    public function test_an_account_without_an_email_confirms_a_sensitive_action_with_its_password(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);
        $owner->forceFill(['email' => null, 'phone' => '01012345678'])->save();

        $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertRedirect(route('dash.confirm'));
        $this->actingAs($owner)->get($this->dashUrl('/confirm'))->assertOk()->assertSee('name="password"', false);
        Notification::assertNothingSent();
        $this->actingAs($owner)->post($this->dashUrl('/confirm'), ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->actingAs($owner)->post($this->dashUrl('/confirm'), ['password' => 'Secret-pass-123'])->assertRedirect($this->dashUrl('/customers/export'));
    }

    public function test_a_sign_in_from_a_new_device_is_emailed_but_not_the_first_one_nor_a_known_device(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $cookie = LoginAlert::cookieName('web', $owner);

        // the very first sign-in of the account: nothing to warn about
        $first = $this->post($this->dashUrl('/login'), self::LOGIN)->assertRedirect(route('dash.home'));
        Notification::assertNotSentTo($owner, NewSignIn::class);
        $known = $first->getCookie($cookie)->getValue();
        $this->post($this->dashUrl('/logout'));

        // another browser (no cookie): an email with the device and the address
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36')
            ->post($this->dashUrl('/login'), self::LOGIN)->assertRedirect(route('dash.home'));
        Notification::assertSentToTimes($owner, NewSignIn::class, 1);
        Notification::assertSentTo($owner, NewSignIn::class, fn (NewSignIn $n) => $n->device === 'Chrome · Android' && $n->ip !== '');
        $this->post($this->dashUrl('/logout'));

        // the first browser again: nothing more
        $this->withCookie($cookie, $known)->post($this->dashUrl('/login'), self::LOGIN)->assertRedirect(route('dash.home'));
        Notification::assertSentToTimes($owner, NewSignIn::class, 1);
    }

    public function test_a_password_reset_also_confirms_the_email(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $token = app('auth.password.broker')->createToken($owner);

        $this->post($this->dashUrl('/reset-password'), ['token' => $token, 'email' => $owner->email, 'password' => 'New-secret-456', 'password_confirmation' => 'New-secret-456'])
            ->assertRedirect(route('dash.login'));
        $this->assertNotNull(User::query()->find($owner->id)->email_verified_at);
    }
}
