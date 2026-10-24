<?php

namespace App\Services\WhatsApp\Drivers;

use App\Services\WhatsApp\InteractiveGateway;
use Illuminate\Support\Facades\Log;

/** Development and tests: nothing leaves the server. The message row in whatsapp_messages is the record. */
final class LogDriver implements InteractiveGateway
{
    public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
    {
        $id = 'log-'.random_token(8);
        Log::info('whatsapp (log driver)', ['id' => $id, 'to' => $to, 'template' => $templateKey, 'locale' => $locale, 'text' => $text]);

        return $id;
    }

    public function sendWithButtons(string $to, string $templateKey, array $params, array $buttons, string $locale = 'ar', string $text = ''): string
    {
        $id = 'log-'.random_token(8);
        Log::info('whatsapp (log driver)', ['id' => $id, 'to' => $to, 'template' => $templateKey, 'locale' => $locale, 'text' => $text,
            'buttons' => array_column($buttons, 'title')]);

        return $id;
    }

    public function reply(string $to, string $text): string
    {
        $id = 'log-'.random_token(8);
        Log::info('whatsapp reply (log driver)', ['id' => $id, 'to' => $to, 'text' => $text]);

        return $id;
    }
}
