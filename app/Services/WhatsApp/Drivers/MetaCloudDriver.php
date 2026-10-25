<?php

namespace App\Services\WhatsApp\Drivers;

use App\Services\WhatsApp\InteractiveGateway;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Official WhatsApp Business Cloud API (Meta). Business-initiated messages must use a pre-approved template:
 * names per message key in config/whatsapp.php, body parameters in Templates::PARAMS order, quick-reply buttons
 * (Templates::BUTTONS) as button components carrying their payloads. Free text only answers a guest inside the
 * 24-hour window they opened. The access token is only ever sent as the Authorization header, never logged nor put
 * in an error.
 *
 * Without arguments it sends from the platform number (config whatsapp.meta); with a phone number id and a token it
 * sends from a restaurant's own number (App\Services\WhatsApp\OwnNumber), where the templates of the same names live in
 * the restaurant's own WhatsApp Business account. A Meta error keeps its code on the exception (190 = token
 * expired or revoked).
 */
final class MetaCloudDriver implements InteractiveGateway
{
    public function __construct(private ?string $phoneId = null, private ?string $token = null) {}

    public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string
    {
        return $this->sendWithButtons($to, $templateKey, $params, [], $locale, $text);
    }

    public function sendWithButtons(string $to, string $templateKey, array $params, array $buttons, string $locale = 'ar', string $text = ''): string
    {
        $components = $params === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params)),
        ]];
        foreach (array_values($buttons) as $index => $button) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => (string) $button['payload']]],
            ];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => (string) (config("whatsapp.templates.$templateKey") ?? $templateKey),
                'language' => ['code' => config("whatsapp.languages.$locale", $locale)],
                'components' => $components,
            ],
        ]);
    }

    public function reply(string $to, string $text): string
    {
        return $this->post([
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to,
            'type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ]);
    }

    /** @param  array<string,mixed>  $payload */
    private function post(array $payload): string
    {
        $phoneId = (string) ($this->phoneId ?? config('whatsapp.meta.phone_id'));
        $token = (string) ($this->token ?? config('whatsapp.meta.token'));
        if ($phoneId === '' || $token === '') {
            throw new WhatsAppException('WhatsApp Cloud API is not configured (WHATSAPP_PHONE_ID / WHATSAPP_TOKEN).');
        }
        $url = rtrim((string) config('whatsapp.meta.base_url'), '/').'/'.config('whatsapp.meta.version').'/'.$phoneId.'/messages';

        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('whatsapp.timeout', 15))->post($url, $payload);
        } catch (ConnectionException) {
            throw new WhatsAppException('WhatsApp Cloud API could not be reached.');
        }
        if ($response->failed()) {
            $error = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
            throw new WhatsAppException(mb_substr(str_replace($token, '***', $error), 0, 200), (int) $response->json('error.code', 0));
        }

        return (string) ($response->json('messages.0.id') ?? throw new WhatsAppException('WhatsApp Cloud API returned no message id.'));
    }
}
