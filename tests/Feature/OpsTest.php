<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Tests\TenantTestCase;

class OpsTest extends TenantTestCase
{
    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_error_pages_render_statically(): void
    {
        foreach (['404', '500', '503'] as $code) {
            $html = view('errors.'.$code)->render();
            $this->assertStringContainsString($code, $html);
            $this->assertStringContainsString('dir="rtl"', $html);
        }
    }

    public function test_admin_command_creates_then_updates(): void
    {
        $this->artisan('egmenu:admin', ['email' => 'Boss@Example.com', '--name' => 'Boss', '--password' => 'long-enough-pass'])->assertSuccessful();
        $this->artisan('egmenu:admin', ['email' => 'boss@example.com', '--name' => 'Boss 2', '--password' => 'another-long-pass'])->assertSuccessful();

        $this->assertSame(1, PlatformAdmin::query()->count());
        $this->assertSame('Boss 2', PlatformAdmin::query()->first()->name);
    }

    public function test_admin_command_rejects_short_password(): void
    {
        $this->artisan('egmenu:admin', ['email' => 'a@example.com', '--password' => 'short'])->assertFailed();
    }

    public function test_doctor_fails_in_debug_and_passes_when_healthy(): void
    {
        config(['app.debug' => true, 'app.env' => 'production']);
        $this->artisan('egmenu:doctor')->assertFailed();

        config(['app.debug' => false, 'mail.default' => 'smtp']);
        // a throw-away public path with its storage link, so the check does not depend on this machine's symlink
        $public = sys_get_temp_dir().'/egmenu-doctor-'.uniqid();
        mkdir($public.'/storage', 0777, true);
        app()->usePublicPath($public);
        $this->artisan('egmenu:doctor')->assertSuccessful();
        rmdir($public.'/storage');
        rmdir($public);
    }
}
