<?php

namespace App\Services\Auth;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The second login step for the two session guards (web = restaurant staff, admin = platform admins).
 * After the password matched, the user is parked in the session (nothing is logged in yet) until a TOTP or
 * recovery code arrives; a device can be remembered for 30 days with a cookie signed per user.
 */
final class TwoFactor
{
    public const CHALLENGE_MINUTES = 10;

    public const REMEMBER_DAYS = 30;

    public const RECOVERY_CODES = 8;

    /** Wrong codes one challenge takes before it is dropped (the password must be typed again). */
    public const MAX_ATTEMPTS = 5;

    /**
     * A wrong code: counted against the challenge itself, so a six-digit code cannot be tried from a thousand
     * addresses. Returns false when the challenge is used up and was dropped.
     */
    public static function failed(Request $request, string $guard): bool
    {
        $data = (array) $request->session()->get("2fa.$guard", []);
        $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            $request->session()->forget("2fa.$guard");

            return false;
        }
        $request->session()->put("2fa.$guard", $data);

        return true;
    }

    /** Starts the challenge: who passed the password, and whether they ticked "remember me". */
    public static function challenge(Request $request, string $guard, Authenticatable $user, bool $remember): void
    {
        $request->session()->put("2fa.$guard", ['id' => $user->getAuthIdentifier(), 'remember' => $remember, 'at' => now()->timestamp]);
    }

    /** The user waiting for their code in this session, or null when there is none or it expired. */
    public static function pending(Request $request, string $guard): User|PlatformAdmin|null
    {
        $data = $request->session()->get("2fa.$guard");
        if (! is_array($data) || ($data['at'] ?? 0) < now()->subMinutes(self::CHALLENGE_MINUTES)->timestamp) {
            return null;
        }
        // central context: the dashboard host has no tenant before login, the user id is unique platform-wide
        $user = ($guard === 'admin' ? PlatformAdmin::query() : User::query())->find($data['id'] ?? 0);

        return $user?->hasTwoFactorEnabled() ? $user : null;
    }

    /** True when $code is the current TOTP, or an unused recovery code (which is consumed). */
    public static function check(User|PlatformAdmin $user, string $code): bool
    {
        $code = trim($code);
        if (Totp::verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return strlen($code) > Totp::DIGITS && $user->useTwoFactorRecoveryCode($code);
    }

    /** Finishes the login once the code matched; optionally trusts this browser for 30 days. */
    public static function complete(Request $request, string $guard, User|PlatformAdmin $user, bool $rememberDevice): void
    {
        $data = (array) $request->session()->pull("2fa.$guard", []);
        Auth::guard($guard)->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        if ($rememberDevice) {
            cookie()->queue(self::rememberCookie($request, $guard, $user));
        }
    }

    /** Does this browser carry a valid "remember this device" cookie for $user? */
    public static function isRemembered(Request $request, string $guard, User|PlatformAdmin $user): bool
    {
        $raw = $request->cookie(self::cookieName($guard));
        if (! is_string($raw) || substr_count($raw, '|') !== 2) {
            return false;
        }
        [$id, $expires, $signature] = explode('|', $raw);
        if ($id !== (string) $user->getAuthIdentifier() || (int) $expires < now()->timestamp) {
            return false;
        }

        return hash_equals(self::sign("$id|$expires", $user), $signature);
    }

    public static function rememberCookie(Request $request, string $guard, User|PlatformAdmin $user): Cookie
    {
        $payload = $user->getAuthIdentifier().'|'.now()->addDays(self::REMEMBER_DAYS)->timestamp;

        return cookie(self::cookieName($guard), $payload.'|'.self::sign($payload, $user), 60 * 24 * self::REMEMBER_DAYS,
            null, null, $request->isSecure(), true, false, 'lax');
    }

    public static function forgetCookie(string $guard): Cookie
    {
        return cookie()->forget(self::cookieName($guard));
    }

    /** Owners can require 2FA of every staff member (setting require_2fa): staff without it only reach the setup page. */
    public static function setupRequired(User $user): bool
    {
        return Settings::get('require_2fa') === '1' && ! $user->hasTwoFactorEnabled();
    }

    /**
     * Every platform admin must use 2FA (config eghub.admin_require_2fa): until it is on, only the security page opens.
     * An owner may let a member who is not an owner do without it (two_factor_optional, e.g. the sales phone).
     */
    public static function adminSetupRequired(PlatformAdmin $admin): bool
    {
        return (bool) config('eghub.admin_require_2fa') && ! $admin->hasTwoFactorEnabled()
            && ! ($admin->two_factor_optional && ! $admin->isOwner());
    }

    public static function cookieName(string $guard): string
    {
        return "eghub_2fa_$guard";
    }

    /** HMAC over the payload, keyed by the app key and the user's current 2FA setup (a reset invalidates it). */
    private static function sign(string $payload, User|PlatformAdmin $user): string
    {
        return hash_hmac('sha256', $payload.'|'.$user->twoFactorFingerprint(), (string) config('app.key'));
    }
}
