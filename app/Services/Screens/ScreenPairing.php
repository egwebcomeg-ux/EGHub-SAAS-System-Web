<?php

namespace App\Services\Screens;

use App\Models\Tenant;
use App\Models\TvPairing;
use App\Models\TvScreen;
use App\Services\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pairing a TV with a restaurant without typing a link with a remote:
 *  1. the device opens eghub.app/screen and asks for a code: it gets a 6-digit code to show and a random secret to
 *     keep (start); only an HMAC of the code and a hash of the secret are stored, and the code lives CODE_MINUTES;
 *  2. the owner types the code in the dashboard (or scans the QR on the TV) and picks the screen (claim): the code
 *     is used once, the screen gets a new device token (any device paired before is unpaired), and the plain token
 *     waits encrypted on the pairing row;
 *  3. the device, asking with its secret, collects the token once (collect); the row forgets it.
 * From then on the device sends the token (header X-Screen-Token, or its cookie) and device() finds its screen.
 * Codes are throttled on both sides (routes) and failed claims are counted per restaurant (the controller).
 */
final class ScreenPairing
{
    public const CODE_MINUTES = 10;

    /** The device keeps its token in this cookie too (httpOnly, path /screen), in case its storage is cleared. */
    public const COOKIE = 'eghub_screen';

    public const HEADER = 'X-Screen-Token';

    /** @return array{code: string, secret: string, expires_at: Carbon} */
    public function start(Request $request): array
    {
        // old rows go when new ones are made: nothing else reads them
        TvPairing::query()->where('expires_at', '<', now()->subHour())->delete();
        for ($try = 0; $try < 20; $try++) {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $taken = TvPairing::query()->where('code_hash', self::codeHash($code))->where('expires_at', '>', now())->exists();
            if (! $taken) {
                break;
            }
        }
        $secret = random_token(24);
        $pairing = TvPairing::query()->create([
            'code_hash' => self::codeHash($code), 'secret_hash' => hash('sha256', $secret),
            'ip' => mb_substr((string) $request->ip(), 0, 45), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        return ['code' => $code, 'secret' => $secret, 'expires_at' => $pairing->expires_at];
    }

    /**
     * Uses a code for $screen in the current restaurant. Null when the code is wrong, used or expired.
     *
     * @throws \DomainException when the restaurant already pairs as many screens as it pays for
     */
    public function claim(string $code, TvScreen $screen): ?TvPairing
    {
        $code = preg_replace('/\D/', '', latin_digits($code));
        if (strlen((string) $code) !== 6) {
            return null;
        }
        if (! self::canPair($screen)) {
            throw new \DomainException('screen limit');
        }

        return DB::transaction(function () use ($code, $screen) {
            $pairing = TvPairing::query()->where('code_hash', self::codeHash($code))->whereNull('claimed_at')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $pairing) {
                return null;
            }
            $token = $screen->issueDeviceToken();
            $pairing->update(['tenant_id' => tenant()->getTenantKey(), 'tv_screen_id' => $screen->id, 'claimed_at' => now(), 'device_token' => $token]);

            return $pairing;
        });
    }

    /**
     * What the device that asked for a code learns when it checks: waiting, expired, used, or paired with its
     * token (handed over once).
     *
     * @return array{state: string, token?: string}
     */
    public function collect(string $secret): array
    {
        if ($secret === '' || strlen($secret) > 100) {
            return ['state' => 'expired'];
        }

        return DB::transaction(function () use ($secret) {
            $pairing = TvPairing::query()->where('secret_hash', hash('sha256', $secret))->lockForUpdate()->first();
            if (! $pairing) {
                return ['state' => 'expired'];
            }
            if (! $pairing->claimed_at) {
                return ['state' => $pairing->isExpired() ? 'expired' : 'waiting'];
            }
            if ($pairing->collected_at || ! $pairing->device_token) {
                return ['state' => 'used'];
            }
            $token = (string) $pairing->device_token;
            $pairing->update(['collected_at' => now(), 'device_token' => null]);

            return ['state' => 'paired', 'token' => $token];
        });
    }

    /** Whether $screen may get a device: the restaurant pairs fewer screens than its plan and add-ons allow. */
    public static function canPair(TvScreen $screen): bool
    {
        if ($screen->isPaired()) {
            return true; // replacing the device of a screen already counted
        }
        $limit = Entitlements::current()->limit('screens');

        return $limit === null || self::pairedCount() < $limit;
    }

    public static function pairedCount(): int
    {
        return TvScreen::query()->whereNotNull('device_token_hash')->count();
    }

    /**
     * The screen of the device that sent this request, with its restaurant's context initialised. Null when the
     * token is missing or no screen has it (unpaired, or paired again on another device).
     */
    public function device(Request $request): ?TvScreen
    {
        $token = (string) ($request->header(self::HEADER) ?: $request->bearerToken() ?: $request->cookie(self::COOKIE));
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $screen = TvScreen::query()->withoutGlobalScopes()->where('device_token_hash', TvScreen::hashDeviceToken($token))->first();
        $tenant = $screen ? Tenant::query()->find($screen->tenant_id) : null;
        if (! $screen || ! $tenant) {
            return null;
        }
        tenancy()->initialize($tenant);

        return TvScreen::query()->find($screen->id);
    }

    /** A paired screen may play: its restaurant is open, the plan has screens and the screen is on. */
    public static function mayPlay(TvScreen $screen): bool
    {
        $tenant = tenant();

        return $tenant && ! $tenant->isSuspended() && Entitlements::current()->allows('tv_screens') && $screen->is_active;
    }

    public static function codeHash(string $code): string
    {
        return hash_hmac('sha256', 'tv-pair|'.$code, (string) config('app.key'));
    }
}
