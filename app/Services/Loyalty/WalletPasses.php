<?php

namespace App\Services\Loyalty;

/**
 * The guest's card as an Apple Wallet pass (.pkpass) or a Google Wallet "Save" link, filled from CardState.
 * No network call is made here: the pass is signed on this server and handed to the phone.
 *
 * Apple (developer.apple.com/documentation/walletpasses): a zip of pass.json, the images, manifest.json (SHA-1 of
 * every file) and "signature" (PKCS #7 detached signature of manifest.json, DER, made with the Pass Type ID
 * certificate and carrying Apple's WWDR intermediate). Config services.wallet.apple: pass_type_id, team_id,
 * certificate (.p12 with its password, or a PEM holding the certificate and its key), wwdr_certificate (PEM).
 *
 * Google (developers.google.com/wallet/retail/loyalty-cards): https://pay.google.com/gp/v/save/{jwt}, the JWT signed
 * RS256 with the service account's private key and carrying the loyalty class and object.
 * Config services.wallet.google: issuer_id, service_account (the JSON key file of the service account).
 *
 * Both passes are a snapshot of the card when saved: the card link on the pass always opens the live card.
 */
final class WalletPasses
{
    public const GOOGLE_SAVE_URL = 'https://pay.google.com/gp/v/save/';

    /** @param array<string,mixed>|null $config services.wallet when null */
    public function __construct(private ?array $config = null) {}

    public function isConfigured(): bool
    {
        return $this->appleConfigured() || $this->googleConfigured();
    }

    public function appleConfigured(): bool
    {
        $c = $this->apple();

        return $c['pass_type_id'] !== '' && $c['team_id'] !== '' && is_file($c['certificate']) && is_file($c['wwdr_certificate'])
            && class_exists(\ZipArchive::class) && function_exists('openssl_pkcs7_sign');
    }

    public function googleConfigured(): bool
    {
        return $this->google()['issuer_id'] !== '' && $this->serviceAccount() !== null;
    }

    /**
     * The "Save to Google Wallet" link of this card, or null when Google Wallet is not configured.
     *
     * @param  array<string,mixed>  $state  CardState::for()
     */
    public function googleSaveUrl(array $state, string $logoUrl): ?string
    {
        $jwt = $this->googleJwt($state, $logoUrl);

        return $jwt === null ? null : self::GOOGLE_SAVE_URL.$jwt;
    }

    /** @param array<string,mixed> $state */
    public function googleJwt(array $state, string $logoUrl): ?string
    {
        $account = $this->serviceAccount();
        if (! $this->googleConfigured() || $account === null) {
            return null;
        }
        $issuer = $this->google()['issuer_id'];
        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false) {
            return null;
        }
        $origin = parse_url($state['url'], PHP_URL_SCHEME).'://'.parse_url($state['url'], PHP_URL_HOST)
            .(($port = parse_url($state['url'], PHP_URL_PORT)) ? ':'.$port : '');
        $claims = [
            'iss' => $account['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => [$origin],
            'payload' => [
                'loyaltyClasses' => [$this->googleClass($state, $issuer, $logoUrl)],
                'loyaltyObjects' => [$this->googleObject($state, $issuer)],
            ],
        ];
        $segments = [self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), self::base64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $segments[] = self::base64url($signature);

        return implode('.', $segments);
    }

    /**
     * The .pkpass bytes of this card, or null when Apple Wallet is not configured.
     *
     * @param  array<string,mixed>  $state  CardState::for()
     * @param  array<string,string>  $images  file name => PNG bytes; icon.png is required by Wallet
     */
    public function applePass(array $state, array $images): ?string
    {
        if (! $this->appleConfigured() || ! isset($images['icon.png'])) {
            return null;
        }
        [$cert, $key] = $this->appleCredentials() ?? [null, null];
        if ($cert === null) {
            return null;
        }
        $files = ['pass.json' => (string) json_encode($this->passJson($state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] + $images;
        $manifest = (string) json_encode(array_map('sha1', $files), JSON_UNESCAPED_SLASHES);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkpass-'.bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            file_put_contents("$dir/manifest.json", $manifest);
            $ok = openssl_pkcs7_sign("$dir/manifest.json", "$dir/signature.smime", $cert, $key, [],
                PKCS7_BINARY | PKCS7_DETACHED, $this->apple()['wwdr_certificate']);
            $signature = $ok ? self::derFromSmime((string) file_get_contents("$dir/signature.smime")) : null;
            if ($signature === null) {
                return null;
            }
            $zip = new \ZipArchive;
            $zip->open("$dir/card.pkpass", \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->addFromString('manifest.json', $manifest);
            $zip->addFromString('signature', $signature);
            $zip->close();

            return (string) file_get_contents("$dir/card.pkpass");
        } finally {
            foreach ((array) glob("$dir/*") as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
    }

    /**
     * pass.json of a store card: the stamps (or points) up front, tier and balance below, the card QR (the same
     * link the cashier scans) and the card link on the back.
     *
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function passJson(array $state): array
    {
        $c = $this->apple();
        $secondary = array_values(array_filter([
            $state['tier'] ? ['key' => 'tier', 'label' => __('Tier'), 'value' => $state['tier']] : null,
            $state['balance'] !== null ? ['key' => 'balance', 'label' => __('My balance'), 'value' => money($state['balance']).' '.$state['currency']] : null,
        ]));

        return [
            'formatVersion' => 1,
            'passTypeIdentifier' => $c['pass_type_id'],
            'serialNumber' => self::serial($state),
            'teamIdentifier' => $c['team_id'],
            'organizationName' => $state['brand'],
            'description' => __('Loyalty card of :name', ['name' => $state['brand']]),
            'logoText' => $state['brand'],
            'backgroundColor' => self::rgb($state['color']),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(255, 255, 255)',
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $state['url'], 'messageEncoding' => 'iso-8859-1']],
            'storeCard' => array_filter([
                'headerFields' => [['key' => 'title', 'label' => '', 'value' => $state['title']]],
                'primaryFields' => [['key' => 'progress', 'label' => self::progressLabel($state), 'value' => self::progress($state)]],
                'secondaryFields' => $secondary,
                'auxiliaryFields' => $state['name'] !== '' ? [['key' => 'name', 'label' => __('Name'), 'value' => $state['name']]] : [],
                'backFields' => [
                    ['key' => 'link', 'label' => __('My card'), 'value' => $state['url']],
                    ['key' => 'how', 'label' => __('How it works'), 'value' => __('Show the QR to the cashier with every order to collect your stamps.')],
                ],
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function googleClass(array $state, string $issuer, string $logoUrl): array
    {
        return [
            'id' => $issuer.'.'.self::idPart('egmenu-'.$state['restaurant'].'-card'),
            'issuerName' => $state['brand'],
            'programName' => $state['title'],
            'programLogo' => ['sourceUri' => ['uri' => $logoUrl]],
            'hexBackgroundColor' => $state['color'],
            'reviewStatus' => 'UNDER_REVIEW',
        ];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function googleObject(array $state, string $issuer): array
    {
        $balance = $state['type'] === 'points' ? ['int' => (int) $state['points']] : ['string' => self::progress($state)];

        return array_filter([
            'id' => $issuer.'.'.self::idPart(self::serial($state)),
            'classId' => $issuer.'.'.self::idPart('egmenu-'.$state['restaurant'].'-card'),
            'state' => 'ACTIVE',
            'accountName' => mb_substr($state['name'] !== '' ? $state['name'] : $state['brand'], 0, 20),
            'loyaltyPoints' => ['label' => self::progressLabel($state), 'balance' => $balance],
            'secondaryLoyaltyPoints' => $state['balance'] !== null
                ? ['label' => __('My balance'), 'balance' => ['string' => money($state['balance']).' '.$state['currency']]] : null,
            'barcode' => ['type' => 'QR_CODE', 'value' => $state['url']],
            'textModulesData' => $state['tier'] ? [['id' => 'tier', 'header' => __('Tier'), 'body' => $state['tier']]] : null,
            'linksModuleData' => ['uris' => [['id' => 'card', 'uri' => $state['url'], 'description' => __('My card')]]],
        ], fn ($v) => $v !== null);
    }

    /** @return array{pass_type_id:string, team_id:string, certificate:string, certificate_password:string, wwdr_certificate:string} */
    private function apple(): array
    {
        $c = (array) (($this->config ?? (array) config('services.wallet'))['apple'] ?? []);

        return [
            'pass_type_id' => trim((string) ($c['pass_type_id'] ?? '')),
            'team_id' => trim((string) ($c['team_id'] ?? '')),
            'certificate' => self::path($c['certificate'] ?? null),
            'certificate_password' => (string) ($c['certificate_password'] ?? ''),
            'wwdr_certificate' => self::path($c['wwdr_certificate'] ?? null),
        ];
    }

    /** @return array{issuer_id:string, service_account:string} */
    private function google(): array
    {
        $c = (array) (($this->config ?? (array) config('services.wallet'))['google'] ?? []);

        return ['issuer_id' => trim((string) ($c['issuer_id'] ?? '')), 'service_account' => self::path($c['service_account'] ?? null)];
    }

    /** @return array{client_email:string, private_key:string}|null */
    private function serviceAccount(): ?array
    {
        $file = $this->google()['service_account'];
        if (! is_file($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }

        return ['client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key']];
    }

    /** @return array{0:\OpenSSLCertificate, 1:\OpenSSLAsymmetricKey}|null the Pass Type ID certificate and its key */
    private function appleCredentials(): ?array
    {
        $c = $this->apple();
        $raw = (string) file_get_contents($c['certificate']);
        if (str_contains($raw, '-----BEGIN')) {
            $cert = openssl_x509_read($raw);
            $key = openssl_pkey_get_private($raw, $c['certificate_password'] !== '' ? $c['certificate_password'] : null);
        } elseif (openssl_pkcs12_read($raw, $bundle, $c['certificate_password'])) {
            $cert = openssl_x509_read($bundle['cert']);
            $key = openssl_pkey_get_private($bundle['pkey']);
        } else {
            return null;
        }

        return $cert && $key ? [$cert, $key] : null;
    }

    /** The base64 "smime.p7s" part of openssl_pkcs7_sign()'s multipart/signed output, as DER. */
    private static function derFromSmime(string $smime): ?string
    {
        $parts = preg_split('/\r?\n\r?\n/', $smime);
        if (! preg_match('/boundary="?([^";\r\n]+)"?/', $smime, $m)) {
            return null;
        }
        // the parts after the first boundary; the signature part's own Content-Type names it
        foreach (array_slice(explode('--'.$m[1], $smime), 1) as $part) {
            [$headers, $body] = preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2) + [1 => ''];
            if (! preg_match('#^Content-Type:\s*application/(x-)?pkcs7-signature#im', $headers)) {
                continue;
            }
            $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);

            return $der === false || $der === '' ? null : $der;
        }

        return null;
    }

    /** @param array<string,mixed> $state */
    private static function progress(array $state): string
    {
        return match ($state['type']) {
            'points' => (string) $state['points'],
            'stamps' => $state['stamps'].' / '.$state['required'],
            default => $state['tier'] ?? $state['brand'],
        };
    }

    /** @param array<string,mixed> $state */
    private static function progressLabel(array $state): string
    {
        return match ($state['type']) {
            'points' => __('Points'),
            'stamps' => __('Stamps'),
            default => $state['tier'] ? __('Tier') : __('My card'),
        };
    }

    /** One pass per restaurant and card token. @param array<string,mixed> $state */
    private static function serial(array $state): string
    {
        return $state['restaurant'].'-'.basename((string) parse_url($state['url'], PHP_URL_PATH));
    }

    /** Google ids allow letters, digits, ".", "_" and "-" only. */
    private static function idPart(string $s): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
    }

    private static function rgb(string $hex): string
    {
        [$r, $g, $b] = sscanf(ltrim(is_hex_color($hex) ? $hex : '#B5451B', '#'), '%02x%02x%02x');

        return "rgb($r, $g, $b)";
    }

    private static function path(mixed $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) ? $path : base_path($path);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
