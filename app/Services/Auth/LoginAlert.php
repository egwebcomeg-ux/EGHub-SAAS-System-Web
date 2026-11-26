<?php

namespace App\Services\Auth;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\NewSignIn;
use Illuminate\Http\Request;

/**
 * "New sign-in to your account" email: every browser an account signs in from gets a long-lived cookie signed for that
 * account; a sign-in from a browser without it emails the account (when, where from, which browser) so a stolen
 * password shows up at once. Called right after the login and before last_login_at moves, so the very first sign-in
 * of an account (signup, a new staff member) sends nothing.
 */
final class LoginAlert
{
    public const COOKIE_DAYS = 400;

    public static function signedIn(Request $request, string $guard, User|PlatformAdmin $user): void
    {
        $name = self::cookieName($guard, $user);
        $known = hash_equals(self::signature($guard, $user), (string) $request->cookie($name));
        cookie()->queue(cookie($name, self::signature($guard, $user), 60 * 24 * self::COOKIE_DAYS, null, null, $request->isSecure(), true, false, 'lax'));
        if ($known || $user->last_login_at === null || blank($user->email)) {
            return;
        }

        $user->notify(new NewSignIn(
            local_time(now()),
            self::device((string) $request->userAgent()),
            (string) $request->ip(),
            route($guard === 'admin' ? 'admin.security' : 'dash.security'),
        ));
    }

    /** "Chrome on Windows": enough to recognise one's own device, nothing more is kept. */
    public static function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iPhone / iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };
        $device = trim($browser.($browser !== '' && $system !== '' ? ' · ' : '').$system);

        return $device !== '' ? $device : __('Unknown device');
    }

    public static function cookieName(string $guard, User|PlatformAdmin $user): string
    {
        return 'eghub_known_'.$guard.'_'.$user->getKey();
    }

    private static function signature(string $guard, User|PlatformAdmin $user): string
    {
        return hash_hmac('sha256', 'known-device|'.$guard.'|'.$user->getKey(), (string) config('app.key'));
    }
}
