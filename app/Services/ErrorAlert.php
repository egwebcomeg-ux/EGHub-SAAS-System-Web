<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the platform's owners by email when something breaks: the same error at most once in 30 minutes, at most 20
 * emails a day, and sent right away because the queue may be what is broken. Failing to send never adds an error.
 */
final class ErrorAlert
{
    private const REPEAT_SECONDS = 1800;

    private const DAILY_LIMIT = 20;

    public static function report(Throwable $e): void
    {
        $where = basename($e->getFile()).':'.$e->getLine();
        $request = app()->runningInConsole() ? 'console' : request()->method().' '.request()->getHost().'/'.request()->path();
        self::send(md5($e::class.$where), class_basename($e).' at '.$where, implode("\n", [
            mb_substr($e->getMessage(), 0, 500),
            'Where: '.$request,
            'Restaurant: '.(tenant()?->getTenantKey() ?? '-'),
        ]));
    }

    public static function notice(string $subject, string $body): void
    {
        self::send(md5($subject), $subject, $body);
    }

    private static function send(string $key, string $subject, string $body): void
    {
        if (! config('eghub.error_alerts')) {
            return;
        }
        try {
            if (! Cache::add("error-alert:$key", 1, self::REPEAT_SECONDS)) {
                return;
            }
            $day = 'error-alert-day:'.now()->toDateString();
            Cache::add($day, 0, 86400);
            if (Cache::increment($day) > self::DAILY_LIMIT) {
                return;
            }
            $to = PlatformAdmin::query()->active()->get()->filter(fn (PlatformAdmin $admin) => $admin->isOwner())->pluck('email')->filter()->all();
            if ($to !== []) {
                Mail::raw($body, fn ($mail) => $mail->to($to)->subject('['.config('app.name').'] '.$subject));
            }
        } catch (Throwable) {
            // the alert must never become the error
        }
    }
}
