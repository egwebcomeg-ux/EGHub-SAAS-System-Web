<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The cPanel account EgHub runs on (config services.cpanel): a restaurant's domain becomes an alias of the app (it
 * opens the same folder, so the request reaches EgHub instead of the server's default site) and AutoSSL is asked for
 * its certificate. Without a token nothing is called and the admin adds the domain by hand, as before.
 */
class HostingDomains
{
    public function configured(): bool
    {
        return filled(config('services.cpanel.user')) && filled(config('services.cpanel.token'));
    }

    /**
     * Adds $domain as an alias (skipped when the account already has it) and starts AutoSSL.
     *
     * @return array{ok: bool, added: bool, error: ?string}
     */
    public function add(string $domain): array
    {
        $domain = strtolower(trim($domain));
        try {
            $list = $this->client()->get('/execute/DomainInfo/list_domains')->json() ?? [];
            if (($list['status'] ?? 0) !== 1) {
                return $this->failed($list['errors'] ?? null);
            }
            $data = $list['data'] ?? [];
            $known = array_map('strtolower', array_merge(
                [(string) ($data['main_domain'] ?? '')],
                (array) ($data['parked_domains'] ?? []),
                (array) ($data['addon_domains'] ?? []),
                (array) ($data['sub_domains'] ?? []),
            ));
            $added = false;
            if (! in_array($domain, $known, true)) {
                // aliases (parked domains) have no UAPI call yet: cPanel API 2 Park::park
                $park = $this->client()->get('/json-api/cpanel', [
                    'cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'Park', 'cpanel_jsonapi_func' => 'park',
                    'domain' => $domain,
                ])->json('cpanelresult') ?? [];
                $row = $park['data'][0] ?? [];
                if ((int) ($row['result'] ?? 0) !== 1) {
                    return $this->failed($row['reason'] ?? $park['error'] ?? null);
                }
                $added = true;
            }
            // the certificate comes a few minutes later; a failed start is not a reason to stop the approval
            $this->client()->get('/execute/SSL/start_autossl_check');

            return ['ok' => true, 'added' => $added, 'error' => null];
        } catch (ConnectionException $e) {
            return $this->failed($e->getMessage());
        }
    }

    private function client(): PendingRequest
    {
        $host = (string) config('services.cpanel.host');

        return Http::baseUrl('https://'.$host.':'.config('services.cpanel.port'))
            ->withHeaders(['Authorization' => 'cpanel '.config('services.cpanel.user').':'.config('services.cpanel.token')])
            ->acceptJson()->timeout(60)
            // the panel's certificate is for the server's hostname, not for the local address the app calls
            ->withOptions(['verify' => ! in_array($host, ['127.0.0.1', 'localhost'], true)]);
    }

    /**
     * @param  string|array<int, string>|null  $error
     * @return array{ok: false, added: false, error: string}
     */
    private function failed(string|array|null $error): array
    {
        $error = is_array($error) ? implode(' ', $error) : (string) $error;

        return ['ok' => false, 'added' => false, 'error' => trim(strip_tags($error)) ?: __('No answer from the hosting panel.')];
    }
}
