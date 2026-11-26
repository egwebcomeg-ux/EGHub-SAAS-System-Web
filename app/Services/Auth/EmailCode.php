<?php

namespace App\Services\Auth;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\AccountCode;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Six-digit codes emailed to an account: the second sign-in step, confirming the email, turning email two-factor on
 * and confirming a sensitive action. One live code per account and purpose, valid MINUTES, used once, TRIES wrong
 * guesses and it is gone. Only an HMAC of the code (bound to the address it went to) is kept, in the cache.
 */
final class EmailCode
{
    public const LOGIN = 'login';

    public const VERIFY = 'verify';

    public const ENABLE = 'enable';

    public const CONFIRM = 'confirm';

    public const MINUTES = 10;

    public const TRIES = 5;

    /** A new email is not sent sooner than this after the last one (the code already sent stays valid). */
    public const RESEND_SECONDS = 60;

    /**
     * Emails a fresh code to the account's address. False when there is no address or the last code went out less
     * than RESEND_SECONDS ago.
     */
    public static function send(User|PlatformAdmin $user, string $purpose, string $action = ''): bool
    {
        $email = (string) $user->email;
        $row = Cache::get(self::key($user, $purpose));
        if ($email === '' || (is_array($row) && $row['sent'] > now()->subSeconds(self::RESEND_SECONDS)->timestamp)) {
            return false;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        self::store($user, $purpose, ['hash' => self::hash($email, $code), 'sent' => now()->timestamp, 'tries' => 0,
            'expires' => now()->addMinutes(self::MINUTES)->timestamp]);

        $locale = $user instanceof HasLocalePreference ? $user->preferredLocale() : app()->getLocale();
        Notification::route('mail', $email)->notify((new AccountCode($code, $purpose, (string) $user->name, $action))->locale($locale));

        return true;
    }

    /** Is a code of this purpose waiting for the account? */
    public static function pending(User|PlatformAdmin $user, string $purpose): bool
    {
        return is_array(Cache::get(self::key($user, $purpose)));
    }

    /** True once, when $code is the live code of this purpose (Arabic digits and spaces accepted). */
    public static function check(User|PlatformAdmin $user, string $purpose, string $code): bool
    {
        $key = self::key($user, $purpose);
        $row = Cache::get($key);
        $code = latin_digits((string) preg_replace('/\s+/', '', $code));
        if (! is_array($row) || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        if (hash_equals($row['hash'], self::hash((string) $user->email, $code))) {
            Cache::forget($key);

            return true;
        }
        $row['tries']++;
        $row['tries'] >= self::TRIES ? Cache::forget($key) : self::store($user, $purpose, $row);

        return false;
    }

    /**
     * "ah***@gmail.com": enough to recognise one's own address on a page anybody might be looking at. Isolated
     * left-to-right, so it reads in order inside an Arabic sentence.
     */
    public static function mask(?string $email): string
    {
        [$name, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');

        return "\u{2066}".mb_substr($name, 0, 2).'***@'.$domain."\u{2069}";
    }

    /** @param  array{hash: string, sent: int, tries: int, expires: int}  $row */
    private static function store(User|PlatformAdmin $user, string $purpose, array $row): void
    {
        Cache::put(self::key($user, $purpose), $row, max(1, $row['expires'] - now()->timestamp));
    }

    private static function key(User|PlatformAdmin $user, string $purpose): string
    {
        return 'account-code:'.$purpose.':'.($user instanceof PlatformAdmin ? 'admin' : 'user').':'.$user->getKey();
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', strtolower($email).'|'.$code, (string) config('app.key'));
    }
}
