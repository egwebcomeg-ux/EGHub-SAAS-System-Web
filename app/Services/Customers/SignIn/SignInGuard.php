<?php

namespace App\Services\Customers\SignIn;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The limits around every guest sign-in method, per restaurant: 20 tries an hour from one IP, and a mobile is locked
 * for an hour after 5 wrong tries (for a day after 10 in a day), whether or not that mobile is a guest here, so the
 * answer never tells. Mobiles and IPs only appear hashed in the limiter keys. The restaurant's audit log gets one
 * row per hour with counts only (signed in / wrong / blocked), no mobile, no IP.
 */
final class SignInGuard
{
    public const IP_PER_HOUR = 20;

    public const PHONE_FAILURES = 5;

    public const PHONE_LOCK_SECONDS = 3600;

    public const PHONE_FAILURES_PER_DAY = 10;

    public const AUDIT_ACTION = 'account.sign_in';

    /** @return 'ok'|'failed'|'blocked' plus the guest on ok */
    public function attempt(GuestSignInMethod $method, string $rawPhone, array $input, string $ip): array
    {
        $ipKey = $this->key('ip', $ip);
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_HOUR)) {
            return $this->result($method, 'blocked');
        }
        RateLimiter::hit($ipKey, 3600);

        $phone = normalize_phone($rawPhone);
        if ($phone === null) {
            return $this->result($method, 'failed');
        }
        $lockKey = $this->key('lock', $phone);
        if (Cache::has($lockKey)) {
            return $this->result($method, 'blocked');
        }

        $customer = $method->attempt($phone, $input);
        if ($customer) {
            RateLimiter::clear($this->key('phone', $phone));

            return $this->result($method, 'ok', $customer);
        }

        $failures = RateLimiter::hit($this->key('phone', $phone), self::PHONE_LOCK_SECONDS);
        $today = RateLimiter::hit($this->key('day', $phone), 86400);
        if ($today >= self::PHONE_FAILURES_PER_DAY) {
            Cache::put($lockKey, true, 86400);
        } elseif ($failures >= self::PHONE_FAILURES) {
            Cache::put($lockKey, true, self::PHONE_LOCK_SECONDS);
            RateLimiter::clear($this->key('phone', $phone));
        }

        return $this->result($method, 'failed');
    }

    private function key(string $kind, string $value): string
    {
        return 'guest-sign-in:'.$kind.':'.tenant()->getTenantKey().':'.hash('sha256', $value);
    }

    /** @return array{0:string, 1:?Customer} */
    private function result(GuestSignInMethod $method, string $outcome, ?Customer $customer = null): array
    {
        $this->count($method->key(), $outcome);

        return [$outcome, $customer];
    }

    /** One audit row per hour and restaurant with the counts of each method (no personal data). */
    private function count(string $method, string $outcome): void
    {
        $row = AuditLog::query()->where('action', self::AUDIT_ACTION)->where('created_at', '>=', now()->startOfHour())->latest('id')->first()
            ?? new AuditLog(['action' => self::AUDIT_ACTION, 'subject' => '', 'ip' => '', 'user_id' => null, 'meta' => []]);
        $meta = (array) $row->meta;
        $meta[$method][$outcome] = (int) ($meta[$method][$outcome] ?? 0) + 1;
        $row->meta = $meta;
        $row->save();
    }
}
