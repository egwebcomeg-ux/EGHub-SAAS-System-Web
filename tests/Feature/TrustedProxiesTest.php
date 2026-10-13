<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Behind Cloudflare the visitor IP comes from X-Forwarded-For, but only when a trusted proxy sent it. */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__ip-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'client_ip' => client_ip(),
            'secure' => $request->isSecure(),
        ]);
    }

    private function probe(string $remoteAddr, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders($headers)
            ->getJson('http://probe.example/__ip-probe');
    }

    public function test_cloudflare_edge_forwards_the_real_client_ip(): void
    {
        $this->probe('173.245.48.10', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.9', 'client_ip' => '203.0.113.9', 'secure' => true]);
    }

    public function test_cloudflare_ipv6_edge_is_trusted(): void
    {
        $this->probe('2606:4700::1', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertJson(['ip' => '203.0.113.9']);
    }

    public function test_spoofed_entries_before_the_cloudflare_hop_are_ignored(): void
    {
        // Cloudflare appends the address it saw; whatever the visitor wrote before it must not win.
        $this->probe('173.245.48.10', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'])
            ->assertJson(['ip' => '203.0.113.9']);
    }

    public function test_untrusted_peer_cannot_spoof_its_ip(): void
    {
        $this->probe('198.51.100.7', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJson(['ip' => '198.51.100.7', 'client_ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_wildcard_trusts_any_peer(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->probe('10.0.0.5', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertJson(['ip' => '203.0.113.9']);
    }
}
