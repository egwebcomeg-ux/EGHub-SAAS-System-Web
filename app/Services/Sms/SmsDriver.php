<?php

namespace App\Services\Sms;

/** One way to hand an SMS to a provider (config/sms.php). */
interface SmsDriver
{
    /**
     * Sends $text to $phone (an Egyptian mobile, 01xxxxxxxxx).
     *
     * @return array{ok:bool, error:?string}
     */
    public function send(string $phone, string $text): array;
}
