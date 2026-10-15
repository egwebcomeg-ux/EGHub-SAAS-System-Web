<?php

namespace App\Services\WhatsApp\Drivers;

use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Official WhatsApp Business Cloud API (Meta). Business-initiated messages must use a pre-approved template:
 * names per message key in config/whatsapp.php, body parameters in Templates::PARAMS order.
 * The access token is only ever sent as the Authorization header, never logged nor put in an error.
 */
final class MetaCloudDriver implements WhatsAppGateway
{
    public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
    {
        $phoneId = (string) config('whatsapp.meta.phone_id');
        $token = (string) config('whatsapp.meta.token');
        if ($phoneId === '' || $token === '') {
            throw new WhatsAppException('WhatsApp Cloud API is not configured (WHATSAPP_PHONE_ID / WHATSAPP_TOKEN).');
        }
        $template = (string) (config("whatsapp.templates.$templateKey") ?? $templateKey);
        $url = rtrim((string) config('whatsapp.meta.base_url'), '/').'/'.config('whatsapp.meta.version').'/'.$phoneId.'/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => config("whatsapp.languages.$locale", $locale)],
                'components' => $params === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params)),
                ]],
            ],
        ];

        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('whatsapp.timeout', 15))->post($url, $payload);
        } catch (ConnectionException) {
            throw new WhatsAppException('WhatsApp Cloud API could not be reached.');
        }
        if ($response->failed()) {
            $error = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
            throw new WhatsAppException(mb_substr(str_replace($token, '***', $error), 0, 200));
        }

        return (string) ($response->json('messages.0.id') ?? throw new WhatsAppException('WhatsApp Cloud API returned no message id.'));
    }
}
