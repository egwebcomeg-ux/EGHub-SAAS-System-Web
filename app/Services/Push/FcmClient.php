<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 API, with a Google service account (config services.fcm: FCM_PROJECT_ID and
 * FCM_CREDENTIALS, the path of the service-account JSON). The OAuth2 access token comes from a JWT signed here with
 * openssl (RS256, no SDK) and is cached until shortly before it expires. Without the configuration every send is a
 * no-op, logged once a day.
 */
class FcmClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** A send's outcome: delivered to FCM, the token is dead (delete it), or anything else (kept, tried next time). */
    public const SENT = 'sent';

    public const INVALID = 'invalid';

    public const FAILED = 'failed';

    /** @var array{client_email:string, private_key:string, token_uri:string}|false|null */
    private array|false|null $account = null;

    public function configured(): bool
    {
        return $this->projectId() !== '' && $this->account() !== null;
    }

    /** Logs once (a day) that pushes are skipped because FCM is not configured. */
    public function noteUnconfigured(): void
    {
        if (Cache::add('fcm.unconfigured.logged', true, now()->addDay())) {
            Log::info('Push notifications skipped: FCM_PROJECT_ID / FCM_CREDENTIALS are not set (or the JSON is unreadable).');
        }
    }

    /**
     * Sends one notification to one device. $data values are strings (FCM requires it); `url` opens on tap.
     *
     * @param  array<string,string>  $data
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        if (! $this->configured()) {
            $this->noteUnconfigured();

            return self::FAILED;
        }
        try {
            $access = $this->accessToken();
            if ($access === null) {
                return self::FAILED;
            }
            $response = Http::withToken($access)->acceptJson()->timeout(10)->post(
                'https://fcm.googleapis.com/v1/projects/'.rawurlencode($this->projectId()).'/messages:send',
                ['message' => [
                    'token' => $token,
                    'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 400)],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
                ]],
            );
        } catch (\Throwable $e) {
            Log::warning('FCM send failed: '.$e->getMessage());

            return self::FAILED;
        }
        if ($response->successful()) {
            return self::SENT;
        }
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey()); // the cached access token was revoked: a fresh one next time
        }
        if (self::isInvalidToken($response->status(), (array) $response->json())) {
            return self::INVALID;
        }
        Log::warning('FCM send refused', ['status' => $response->status(), 'error' => $response->json('error.status')]);

        return self::FAILED;
    }

    /**
     * Whether FCM says the token is dead: 404 / UNREGISTERED (the app was removed or the token rotated), or a
     * 400 INVALID_ARGUMENT that names the token itself.
     *
     * @param  array<string,mixed>  $json
     */
    public static function isInvalidToken(int $status, array $json): bool
    {
        $error = (array) ($json['error'] ?? []);
        $codes = collect((array) ($error['details'] ?? []))->pluck('errorCode')->filter()->all();
        if ($status === 404 || ($error['status'] ?? null) === 'NOT_FOUND' || in_array('UNREGISTERED', $codes, true)) {
            return true;
        }

        return $status === 400 && in_array('INVALID_ARGUMENT', $codes, true)
            && str_contains(strtolower((string) ($error['message'] ?? '')), 'registration token');
    }

    /** The OAuth2 access token for FCM, cached until five minutes before it expires. */
    public function accessToken(): ?string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $account = $this->account();
        if ($account === null) {
            return null;
        }
        $response = Http::asForm()->acceptJson()->timeout(10)->post($account['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion(),
        ]);
        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            Log::warning('FCM access token refused', ['status' => $response->status(), 'error' => $response->json('error')]);

            return null;
        }
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /** The signed JWT (RS256) Google exchanges for an access token: one hour, scope firebase.messaging. */
    public function assertion(?int $now = null): string
    {
        $account = $this->account();
        if ($account === null) {
            throw new \RuntimeException('FCM is not configured.');
        }
        $now ??= time();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode([
                'iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $account['token_uri'],
                'iat' => $now, 'exp' => $now + 3600,
            ], JSON_UNESCAPED_SLASHES)),
        ];
        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The FCM service-account key could not sign.');
        }
        $segments[] = self::base64url($signature);

        return implode('.', $segments);
    }

    private function projectId(): string
    {
        return trim((string) config('services.fcm.project_id'));
    }

    /** @return array{client_email:string, private_key:string, token_uri:string}|null */
    private function account(): ?array
    {
        if ($this->account === null) {
            $path = (string) config('services.fcm.credentials');
            $json = $path !== '' && is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            $this->account = is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key'])
                ? ['client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key'],
                    'token_uri' => (string) ($json['token_uri'] ?? self::TOKEN_URI) ?: self::TOKEN_URI]
                : false;
        }

        return $this->account ?: null;
    }

    private function cacheKey(): string
    {
        return 'fcm.access.'.md5($this->projectId().'|'.($this->account()['client_email'] ?? ''));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
