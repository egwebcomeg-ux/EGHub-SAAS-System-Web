<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth\SocialProviders;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google / Facebook" for restaurant staff, on the dashboard host only (the state lives in this
 * host's session, so both the login and the register page start here). On the way back:
 *  - a linked provider account, or a user with the same verified email → signed in like the password login
 *    (inactive users and removed restaurants refused, the 2FA step when it is on); email is unique platform-wide,
 *    so the user's own restaurant is the only one they can land in;
 *  - nobody → the register page on the main domain with the name and the verified email filled in.
 */
class SocialLoginController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(SocialProviders::isEnabled($provider), 404);
        if (Auth::guard('web')->check()) {
            return redirect()->route('dash.home');
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SocialProviders::isEnabled($provider), 404);
        $label = SocialProviders::label($provider);

        // the owner pressed "Cancel" on the consent screen (?error=access_denied)
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail(__('Sign-in with :provider was cancelled. Try again, or sign in with your email and password.', ['provider' => $label]));
        }
        try {
            $identity = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->fail(__('The sign-in took too long or was opened in another tab. Try again.'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail(__('We could not reach :provider right now. Try again in a minute, or sign in with your email and password.', ['provider' => $label]));
        }

        $email = strtolower(trim((string) $identity->getEmail()));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $this->emailIsVerified($provider, $identity)) {
            return $this->fail(__('Your :provider account has no confirmed email. Confirm it there first, or sign in with your email and password.', ['provider' => $label]));
        }

        // central context (no tenant yet): a linked account first, then the email, both unique platform-wide
        $linked = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', (string) $identity->getId())->first();
        $user = $linked?->user ?? User::query()->where('email', $email)->first();

        if (! $user) {
            return $this->toRegister($provider, $identity, $email);
        }
        if (! AuthController::mayEnter($user)) {
            return $this->fail(__('Your account is not active.'));
        }
        if (! $linked && SocialAccount::query()->where('user_id', $user->id)->where('provider', $provider)->exists()) {
            // this user already linked a different account of the same provider: never swap it silently
            return $this->fail(__('Your account is linked to another :provider account. Sign in with that one, or with your email and password.', ['provider' => $label]));
        }
        $account = $linked ?? SocialAccount::query()->create([
            'user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => (string) $identity->getId(), 'email' => $email,
        ]);
        $account->forceFill(['last_used_at' => now()])->save();

        if ($user->hasTwoFactorEnabled() && ! TwoFactor::isRemembered($request, 'web', $user)) {
            $this->audit($user, $provider, ['two_factor' => 'pending']);
            TwoFactor::challenge($request, 'web', $user, false);

            return redirect()->route('dash.two-factor');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit($user, $provider);

        return redirect()->intended(route('dash.home'));
    }

    /**
     * Google says so explicitly (OpenID `email_verified`); Facebook only hands out an email the person has
     * confirmed with Facebook, and has no flag for it.
     */
    private function emailIsVerified(string $provider, ProviderUser $identity): bool
    {
        if ($provider === 'facebook') {
            return true;
        }
        $raw = (array) ($identity->user ?? []);

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sessions are per host, so the verified identity crosses to the register page as a one-time random token
     * (the identity itself stays in the cache for SocialProviders::SIGNUP_MINUTES).
     */
    private function toRegister(string $provider, ProviderUser $identity, string $email): RedirectResponse
    {
        $token = Str::random(48);
        Cache::put(SocialProviders::signupKey($token), [
            'provider' => $provider,
            'id' => (string) $identity->getId(),
            'email' => $email,
            'name' => mb_substr(trim((string) $identity->getName()), 0, 80),
        ], now()->addMinutes(SocialProviders::SIGNUP_MINUTES));

        return redirect()->away(platform_url(null, '/register?social='.$token));
    }

    /** @param array<string, string> $meta */
    private function audit(User $user, string $provider, array $meta = []): void
    {
        $tenant = Tenant::query()->find($user->tenant_id);
        if (! $tenant) {
            return;
        }
        tenancy()->initialize($tenant);
        try {
            Audit::record('login.'.$provider, $user->name, ['id' => $user->id] + $meta, $user->id);
        } finally {
            tenancy()->end();
        }
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('dash.login')->withErrors(['login' => $message]);
    }
}
