<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Does a restaurant's domain point to our server (its A record = the server IP of the admin settings)? A hint only:
 * EgWeb still approves the domain after adding it on the server with SSL. The last admin check of each domain is
 * kept for an hour so the requests page shows it without asking the DNS on every load.
 */
class DomainDns
{
    public const REMEMBER_MINUTES = 60;

    /** The IPv4 address $host resolves to, null when it does not resolve. The only network call. */
    public function resolve(string $host): ?string
    {
        $ip = gethostbyname($host);

        return $ip === $host ? null : $ip;
    }

    /**
     * Checks $domain now and remembers the result.
     *
     * @return array{points: ?bool, ip: ?string, server: string, checked_at: string}
     */
    public function check(Domain $domain): array
    {
        $server = (string) PlatformSetting::get('server_ip', '');
        $ip = $this->resolve($domain->domain);
        $result = [
            // null: no server IP in the settings, nothing to compare with
            'points' => $server === '' ? null : $ip === $server,
            'ip' => $ip,
            'server' => $server,
            'checked_at' => now()->toIso8601String(),
        ];
        Cache::put($this->key($domain), $result, now()->addMinutes(self::REMEMBER_MINUTES));

        return $result;
    }

    /**
     * The last check of $domain within the hour, null when it was not checked.
     *
     * @return array{points: ?bool, ip: ?string, server: string, checked_at: string}|null
     */
    public function last(Domain $domain): ?array
    {
        $result = Cache::get($this->key($domain));

        return is_array($result) ? $result : null;
    }

    private function key(Domain $domain): string
    {
        return 'domain-dns:'.$domain->id.':'.$domain->domain;
    }
}
