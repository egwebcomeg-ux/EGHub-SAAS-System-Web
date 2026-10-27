<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Any provider with a plain HTTP API: the URL, the field names, an optional bearer token and fixed extra fields come
 * from the environment (config/sms.php "http"). A 2xx answer counts as sent.
 */
final class HttpSmsDriver implements SmsDriver
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private string $sender) {}

    public function send(string $phone, string $text): array
    {
        parse_str((string) ($this->config['extra'] ?? ''), $extra);
        $payload = $extra + [
            (string) $this->config['to_field'] => self::phone($phone, (string) ($this->config['phone_format'] ?? 'international')),
            (string) $this->config['text_field'] => $text,
        ];
        if ($this->sender !== '' && ($this->config['sender_field'] ?? '') !== '') {
            $payload[(string) $this->config['sender_field']] = $this->sender;
        }
        try {
            $request = Http::timeout(max(1, (int) ($this->config['timeout'] ?? 10)))->acceptJson();
            if (! empty($this->config['token'])) {
                $request = $request->withToken((string) $this->config['token']);
            }
            $url = (string) $this->config['url'];
            $response = strtolower((string) ($this->config['method'] ?? 'post')) === 'get'
                ? $request->get($url, $payload)
                : (($this->config['format'] ?? 'form') === 'json' ? $request->post($url, $payload) : $request->asForm()->post($url, $payload));

            return $response->successful() ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'HTTP '.$response->status()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 180)];
        }
    }

    /** 01012345678 → +201012345678 (e164) | 201012345678 (international) | 01012345678 (local) */
    public static function phone(string $phone, string $format): string
    {
        $national = ltrim($phone, '0');

        return match ($format) {
            'e164' => '+20'.$national,
            'local' => '0'.$national,
            default => '20'.$national,
        };
    }
}
