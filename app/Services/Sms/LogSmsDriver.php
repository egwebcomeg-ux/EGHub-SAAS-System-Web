<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Local tries: every SMS goes to the log instead of a provider. */
final class LogSmsDriver implements SmsDriver
{
    public function send(string $phone, string $text): array
    {
        Log::info('SMS', ['to' => $phone, 'text' => $text]);

        return ['ok' => true, 'error' => null];
    }
}
