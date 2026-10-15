<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Every response carries the browser hardening headers; HSTS only once the connection is https. */
class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__plain', fn () => 'ok');
        Route::get('/__framed', fn () => response('ok')->header('X-Frame-Options', 'ALLOWALL'));
    }

    public function test_plain_http_response_has_hardening_headers_without_hsts(): void
    {
        $this->get('http://egmenu.test/__plain')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_responses_add_hsts_for_a_year_including_subdomains(): void
    {
        $this->get('https://egmenu.test/__plain')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_cloudflare_forwarded_https_counts_as_secure(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://egmenu.test/__plain')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_a_header_set_by_the_route_is_kept(): void
    {
        $this->get('http://egmenu.test/__framed')
            ->assertHeader('X-Frame-Options', 'ALLOWALL')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
