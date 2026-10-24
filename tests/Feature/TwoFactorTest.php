<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Two-factor authentication (TOTP, RFC 6238) for restaurant staff and platform admins: setup with a QR and a first
 * code, recovery codes shown once, the second login step, remembered devices, the owner's "require 2FA" setting
 * and the platform admin's support reset.
 */
class TwoFactorTest extends TenantTestCase
{
    private const SETTINGS = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
        'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    /** Turns 2FA on for $user straight away; returns [secret, recovery codes]. */
    private function enable(User|PlatformAdmin $user): array
    {
        $secret = Totp::secret();
        $user->startTwoFactorSetup($secret);

        return [$secret, $user->confirmTwoFactor()];
    }

    /** A 6-digit code that is certainly not valid for $secret right now. */
    private function wrongCode(string $secret): string
    {
        return Totp::code($secret) === '000000' ? '111111' : '000000';
    }

    public function test_totp_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertSame('050471', Totp::code($secret, 1111111111));
        // one step of drift either side is accepted, two are not; anything but 6 digits is refused
        $this->assertTrue(Totp::verify($secret, '287082', 1, 59 + 30));
        $this->assertTrue(Totp::verify($secret, '287 082', 1, 59 - 30));
        $this->assertFalse(Totp::verify($secret, '287082', 1, 59 + 61));
        $this->assertFalse(Totp::verify($secret, '28708', 1, 59));
        $this->assertFalse(Totp::verify($secret, 'abcdef', 1, 59));
        $this->assertSame(32, strlen(Totp::secret()));
        $this->assertStringStartsWith('otpauth://totp/EgHub:owner%40cafe.test?secret='.$secret.'&issuer=EgHub', Totp::uri('EgHub', 'owner@cafe.test', $secret));
    }

    public function test_owner_enables_two_factor_by_scanning_and_confirming_a_code(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()
            ->assertSee('data-two-factor-state="off"', false)->assertSee(__('Turn on two-factor authentication'));

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor'))->assertRedirect(route('dash.security'));
        $owner->refresh();
        $secret = $owner->two_factor_secret;
        $this->assertSame(32, strlen((string) $secret));
        $this->assertFalse($owner->hasTwoFactorEnabled());

        // the QR (otpauth URI) and the key by hand are on the page; the secret is encrypted in the database
        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-two-factor-qr', false)->assertSee($secret);
        $raw = DB::table('users')->where('id', $owner->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));

        // a wrong code does not turn it on
        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/confirm'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->assertFalse($owner->fresh()->hasTwoFactorEnabled());

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/confirm'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('dash.security'))->assertSessionHas('two_factor_recovery_codes');
        $codes = session('two_factor_recovery_codes');
        $this->assertCount(8, $codes);
        $owner->refresh();
        $this->assertTrue($owner->hasTwoFactorEnabled());
        $this->assertSame(8, $owner->twoFactorRecoveryCodesLeft());
        $this->assertNotContains($codes[0], $owner->two_factor_recovery_codes);   // only hashes are kept

        // shown once, on the next page load
        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-recovery-codes', false)->assertSee($codes[0])
            ->assertSee('data-two-factor-state="on"', false);
        $this->actingAs($owner)->get($this->dashUrl('/security'))->assertOk()->assertDontSee('data-recovery-codes', false);
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'two_factor.enabled')->count()));
    }

    public function test_login_asks_for_the_code_and_accepts_totp_or_a_recovery_code_once(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        [$secret, $codes] = $this->enable($owner);
        $login = ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'];

        // password alone is not enough any more
        $this->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
        $this->assertGuest('web');
        $this->get($this->dashUrl('/two-factor'))->assertOk()->assertSee('data-two-factor-form', false);

        $this->post($this->dashUrl('/two-factor'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret)])->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->post($this->dashUrl('/logout'));

        // a recovery code signs in once
        $this->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
        $this->post($this->dashUrl('/two-factor'), ['code' => strtoupper($codes[0])])->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame(7, $owner->fresh()->twoFactorRecoveryCodesLeft());
        $this->post($this->dashUrl('/logout'));

        $this->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
        $this->post($this->dashUrl('/two-factor'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest('web');
        $this->post($this->dashUrl('/two-factor'), ['code' => $codes[1]])->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_the_challenge_is_rate_limited_and_needs_a_pending_login(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        [$secret] = $this->enable($owner);

        // nothing pending: back to the login page
        $this->get($this->dashUrl('/two-factor'))->assertRedirect(route('dash.login'));

        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'])->assertRedirect(route('dash.two-factor'));
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->dashUrl('/two-factor'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        }
        $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret)])->assertStatus(429);
        $this->assertGuest('web');

        // the challenge expires after 10 minutes; a code without a pending login goes back to the login page
        $this->travel(11)->minutes();
        $this->get($this->dashUrl('/two-factor'))->assertRedirect(route('dash.login'));
        $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret)])->assertRedirect(route('dash.login'));
        $this->assertGuest('web');
    }

    public function test_a_remembered_device_skips_the_code_for_30_days(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        [$secret] = $this->enable($owner);
        $login = ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'];
        $cookie = TwoFactor::cookieName('web');

        $this->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
        $response = $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret), 'remember_device' => '1'])->assertRedirect(route('dash.home'));
        $response->assertCookie($cookie);
        $value = $response->getCookie($cookie)->getValue();
        $this->post($this->dashUrl('/logout'));

        // with the cookie: straight in
        $this->withCookie($cookie, $value)->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.home'));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->post($this->dashUrl('/logout'));

        // the cookie belongs to this user only
        $b = $this->restaurant('cafe-b');
        $this->enable($this->owner($b));
        $this->withCookie($cookie, $value)->post($this->dashUrl('/login'), ['login' => 'owner@cafe-b.test', 'password' => 'Secret-pass-123'])
            ->assertRedirect(route('dash.two-factor'));

        // a tampered value is ignored
        $this->withCookie($cookie, $owner->id.'|'.now()->addDays(30)->timestamp.'|forged')->post($this->dashUrl('/login'), $login)
            ->assertRedirect(route('dash.two-factor'));

        // after a reset and a new setup the old cookie is no longer trusted
        $owner->disableTwoFactor();
        $this->enable($owner->fresh());
        $this->withCookie($cookie, $value)->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));

        // and it expires
        [$secret] = $this->enable($owner->fresh());
        $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret), 'remember_device' => '1'])->assertRedirect(route('dash.home'));
        $fresh = $this->post($this->dashUrl('/logout'));
        $this->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
        $value = $this->post($this->dashUrl('/two-factor'), ['code' => Totp::code($secret), 'remember_device' => '1'])->getCookie($cookie)->getValue();
        $this->post($this->dashUrl('/logout'));
        $this->travel(31)->days();
        $this->withCookie($cookie, $value)->post($this->dashUrl('/login'), $login)->assertRedirect(route('dash.two-factor'));
    }

    public function test_turning_two_factor_off_needs_the_current_password(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->enable($owner);

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/disable'), ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->assertTrue($owner->fresh()->hasTwoFactorEnabled());

        $this->actingAs($owner)->post($this->dashUrl('/security/two-factor/disable'), ['password' => 'Secret-pass-123'])
            ->assertRedirect(route('dash.security'))->assertSessionHasNoErrors();
        $owner->refresh();
        $this->assertFalse($owner->hasTwoFactorEnabled());
        $this->assertNull($owner->two_factor_secret);
        $this->assertNull($owner->two_factor_recovery_codes);
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'two_factor.disabled')->count()));

        // the next login needs the password only
        $this->post($this->dashUrl('/logout'));
        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'])->assertRedirect(route('dash.home'));
    }

    public function test_the_owner_can_require_two_factor_from_every_staff_member(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->enable($owner);   // the rule applies to the owner too, so they set it up first

        // the setting is the owner's alone
        $this->actingAs($manager)->post($this->dashUrl('/settings'), self::SETTINGS + ['require_2fa' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($a, fn () => Settings::get('require_2fa')));
        $this->actingAs($manager)->get($this->dashUrl('/settings'))->assertOk()->assertDontSee('name="require_2fa"', false);

        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="require_2fa"', false);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['require_2fa' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('require_2fa')));

        // staff without 2FA only reach the setup page (and can leave); the owner, who has it, is not affected
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk();
        $this->actingAs($cashier)->get($this->dashUrl('/'))->assertRedirect(route('dash.security'));
        $this->actingAs($cashier)->get($this->dashUrl('/pos'))->assertRedirect(route('dash.security'));
        $this->actingAs($cashier)->get($this->dashUrl('/security'))->assertOk()->assertSee('data-two-factor-required', false);
        $this->actingAs($cashier)->post($this->dashUrl('/logout'))->assertRedirect(route('dash.login'));

        // once set up, the dashboard opens
        $this->enable($cashier);
        $this->actingAs($cashier)->get($this->dashUrl('/'))->assertOk();

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['require_2fa' => '0'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get($this->dashUrl('/'))->assertOk();
    }

    public function test_platform_admins_have_the_same_flow_on_their_own_host(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/security'))->assertOk()->assertSee('data-two-factor-state="off"', false);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor'))->assertRedirect(route('admin.security'));
        $secret = $admin->fresh()->two_factor_secret;
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor/confirm'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('admin.security'))->assertSessionHas('two_factor_recovery_codes');
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertNotSame($secret, DB::table('platform_admins')->where('id', $admin->id)->value('two_factor_secret'));
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/logout'));

        $login = ['email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123'];
        $this->post($this->adminUrl('/login'), $login)->assertRedirect(route('admin.two-factor'));
        $this->assertGuest('admin');
        $this->get($this->adminUrl('/two-factor'))->assertOk()->assertSee('data-two-factor-form', false);
        $this->post($this->adminUrl('/two-factor'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->post($this->adminUrl('/two-factor'), ['code' => Totp::code($secret)])->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($admin, 'admin');

        // a wrong password never reaches the challenge
        $this->post($this->adminUrl('/logout'));
        $this->post($this->adminUrl('/login'), ['email' => 'admin@egmenu.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->get($this->adminUrl('/two-factor'))->assertRedirect(route('admin.login'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor/disable'), ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor/disable'), ['password' => 'Secret-pass-123'])->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_platform_admin_resets_a_tenant_users_two_factor_with_an_audit_line(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $owner = $this->owner($a);
        $ownerB = $this->owner($b);
        $this->enable($owner);
        $this->enable($ownerB);
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        // a restaurant owner cannot use the admin action
        $this->actingAs($owner)->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/reset-2fa"))->assertRedirect(route('admin.login'));
        $this->assertTrue($owner->fresh()->hasTwoFactorEnabled());

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertSee(__('Reset 2FA'));
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/reset-2fa"))->assertSessionHasNoErrors();
        $this->assertFalse($owner->fresh()->hasTwoFactorEnabled());
        $this->assertTrue($ownerB->fresh()->hasTwoFactorEnabled());

        $line = $this->as($a, fn () => AuditLog::query()->where('action', 'two_factor.reset_by_platform')->first());
        $this->assertNotNull($line);
        $this->assertSame('admin@egmenu.test', $line->meta['admin']);
        $this->assertSame(0, $this->as($b, fn () => AuditLog::query()->where('action', 'two_factor.reset_by_platform')->count()));

        // a user of another restaurant is not reachable through this one; a user without 2FA is an error, not a crash
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$ownerB->id}/reset-2fa"))->assertNotFound();
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/reset-2fa"))->assertSessionHasErrors('two_factor');

        // the owner signs in with the password only and can set it up again (fresh session: in production every host
        // has its own cookies, here the admin visit above left an intended URL behind)
        $this->flushSession();
        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'])->assertRedirect(route('dash.home'));
        $this->assertInstanceOf(Tenant::class, $a);
    }
}
