<?php

namespace App\Services\WhatsApp\Drivers;

use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Any other provider (local Egyptian WhatsApp gateways): a JSON POST to WHATSAPP_WEBHOOK_URL with the shared
 * secret in the X-EgMenu-Secret header. The provider answers 2xx with {"id": "..."} (or "message_id").
 */
final class WebhookDriver implements WhatsAppGateway
{
    public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
    {
        $url = (string) config('whatsapp.webhook.url');
        $secret = (string) config('whatsapp.webhook.secret');
        if ($url === '' || $secret === '') {
            throw new WhatsAppException('WhatsApp webhook is not configured (WHATSAPP_WEBHOOK_URL / WHATSAPP_WEBHOOK_SECRET).');
        }

        try {
            $response = Http::withHeaders(['X-EgMenu-Secret' => $secret])->acceptJson()->timeout((int) config('whatsapp.timeout', 15))
                ->post($url, ['to' => $to, 'template' => $templateKey, 'params' => $params, 'locale' => $locale, 'text' => $text]);
        } catch (ConnectionException) {
            throw new WhatsAppException('WhatsApp webhook could not be reached.');
        }
        if ($response->failed()) {
            throw new WhatsAppException('WhatsApp webhook answered HTTP '.$response->status().'.');
        }

        return (string) ($response->json('id') ?? $response->json('message_id') ?? 'webhook-'.random_token(6));
    }
}
