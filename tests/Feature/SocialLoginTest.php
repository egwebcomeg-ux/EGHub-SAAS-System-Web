<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * "Continue with Google / Facebook" for restaurant staff: buttons only when the keys are set, sign-in of an existing
 * user by verified email (linked, audited, 2FA and inactive accounts respected), and signup of a new owner with the
 * verified email filled in and locked. Socialite is mocked; no provider is called.
 */
class SocialLoginTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'google-test-id', 'services.google.client_secret' => 'google-test-secret',
            'services.facebook.client_id' => null, 'services.facebook.client_secret' => null,
        ]);
    }

    /** The provider answers with this person on the callback. */
    private function googleReturns(string $email, bool $verified = true, string $id = 'g-123', string $name = 'Sara Adel'): void
    {
        $identity = (new ProviderUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function returnFromGoogle(): TestResponse
    {
        return $this->get($this->dashUrl('/auth/google/callback?code=abc&state=xyz'));
    }

    public function test_buttons_show_only_for_providers_whose_keys_are_set(): void
    {
        $this->get($this->dashUrl('/login'))->assertOk()
            ->assertSee(__('Continue with Google'))->assertDontSee(__('Continue with Facebook'))
            ->assertSee($this->dashUrl('/auth/google/redirect'), false);
        $this->get($this->menuUrl('register'))->assertOk()->assertSee(__('Continue with Google'));
        $this->get($this->dashUrl('/auth/facebook/redirect'))->assertNotFound();

        config(['services.google.client_id' => null]);
        $this->get($this->dashUrl('/login'))->assertOk()->assertDontSee('data-social-buttons', false);
        $this->get($this->menuUrl('register'))->assertOk()->assertDontSee('data-social-buttons', false);
        $this->get($this->dashUrl('/auth/google/redirect'))->assertNotFound();
    }

    public function test_an_existing_user_signs_in_with_the_verified_email_and_the_account_is_linked(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $this->googleReturns('OWNER@cafe-a.test');

        $this->returnFromGoogle()->assertRedirect(route('dash.home'));

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertDatabaseHas('social_accounts', ['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-123']);
        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->assertSame('login.google', AuditLog::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-a')->value('action'));
    }

    public function test_a_linked_account_signs_in_even_with_another_provider_email_and_never_changes_the_users_email(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        SocialAccount::query()->create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-123', 'email' => 'owner@cafe-a.test']);
        $this->googleReturns('new-address@gmail.test');

        $this->returnFromGoogle()->assertRedirect(route('dash.home'));

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame('owner@cafe-a.test', $owner->fresh()->email);
    }

    public function test_two_factor_is_still_asked_after_google(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $owner->startTwoFactorSetup(Totp::secret());
        $owner->confirmTwoFactor();
        $this->googleReturns('owner@cafe-a.test');

        $this->returnFromGoogle()->assertRedirect(route('dash.two-factor'));

        $this->assertGuest('web');
        $this->get($this->dashUrl('/two-factor'))->assertOk()->assertSee('data-two-factor-form', false);
    }

    public function test_inactive_users_and_removed_restaurants_are_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $owner->forceFill(['is_active' => false])->save();
        $this->googleReturns('owner@cafe-a.test');

        $this->returnFromGoogle()->assertRedirect(route('dash.login'))->assertSessionHasErrors(['login' => __('Your account is not active.')]);
        $this->assertGuest('web');

        $owner->forceFill(['is_active' => true])->save();
        Tenant::query()->findOrFail('cafe-a')->delete();
        $this->returnFromGoogle()->assertRedirect(route('dash.login'))->assertSessionHasErrors(['login' => __('Your account is not active.')]);
        $this->assertGuest('web');
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $this->googleReturns('owner@cafe-a.test', verified: false);

        $this->returnFromGoogle()->assertRedirect(route('dash.login'))
            ->assertSessionHasErrors(['login' => __('Your :provider account has no confirmed email. Confirm it there first, or sign in with your email and password.', ['provider' => __('Google')])]);

        $this->assertGuest('web');
        $this->assertSame(0, SocialAccount::query()->where('user_id', $owner->id)->count());
    }

    public function test_cancelled_consent_and_provider_failures_come_back_with_a_message(): void
    {
        $this->get($this->dashUrl('/auth/google/callback?error=access_denied'))->assertRedirect(route('dash.login'))
            ->assertSessionHasErrors(['login' => __('Sign-in with :provider was cancelled. Try again, or sign in with your email and password.', ['provider' => __('Google')])]);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->andThrowExceptions([new InvalidStateException, new RuntimeException('Google is down')]);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->returnFromGoogle()->assertRedirect(route('dash.login'))
            ->assertSessionHasErrors(['login' => __('The sign-in took too long or was opened in another tab. Try again.')]);
        $this->returnFromGoogle()->assertRedirect(route('dash.login'))
            ->assertSessionHasErrors(['login' => __('We could not reach :provider right now. Try again in a minute, or sign in with your email and password.', ['provider' => __('Google')])]);
        $this->assertGuest('web');
    }

    public function test_a_new_owner_signs_up_with_the_verified_email_locked_and_no_password(): void
    {
        $this->googleReturns('sara@gmail.test', name: 'Sara Adel');

        $to = $this->returnFromGoogle()->headers->get('Location');
        $this->assertStringStartsWith($this->menuUrl('register?social='), $to);
        $this->assertGuest('web');

        // the token is one-time: it moves into this host's session and the address is cleaned
        $this->get($to)->assertRedirect(route('register'));
        $this->get($to)->assertRedirect(route('register'))->assertSessionHasErrors('email');
        $this->get($this->menuUrl('register'))->assertOk()
            ->assertSee('data-social-signup="google"', false)->assertSee('value="sara@gmail.test"', false)->assertSee('value="Sara Adel"', false)
            ->assertDontSee('id="password"', false)->assertDontSee('data-social-buttons', false);

        // a typed email never replaces the verified one; no password is needed
        $response = $this->post($this->menuUrl('register'), [
            'name' => 'كافيه سارة', 'slug' => 'sara-cafe', 'owner_name' => 'Sara Adel', 'email' => 'someone-else@example.test',
            'phone' => '01066666666', 'terms' => '1', 'business_type' => 'cafe',
        ]);
        $response->assertRedirect();
        $this->assertStringContainsString('/welcome/', (string) $response->headers->get('Location'));

        $owner = User::query()->where('tenant_id', 'sara-cafe')->firstOrFail();
        $this->assertSame('sara@gmail.test', $owner->email);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame('trialing', Tenant::query()->findOrFail('sara-cafe')->subscription()->first()->status);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-123']);

        // the normal welcome follows: signed in, then the modules step
        $this->get((string) $response->headers->get('Location'))->assertRedirect(route('dash.modules', ['welcome' => 1]));
        $this->assertAuthenticatedAs($owner, 'web');
    }
}
