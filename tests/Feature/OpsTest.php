<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class OpsTest extends TenantTestCase
{
    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_error_pages_render_statically(): void
    {
        foreach (['401', '403', '404', '419', '429', '500', '503'] as $code) {
            $html = view('errors.'.$code)->render();
            $this->assertStringContainsString($code, $html);
            $this->assertStringContainsString('dir="rtl"', $html);
        }
    }

    public function test_error_page_titles_follow_the_language(): void
    {
        app()->setLocale('ar');
        $this->assertStringContainsString('<title>انتهت صلاحية الصفحة · EgHub</title>', view('errors.419')->render());
        $this->assertStringContainsString('<title>الصفحة غير موجودة · EgHub</title>', view('errors.404')->render());

        app()->setLocale('en');
        $html = view('errors.419')->render();
        $this->assertStringContainsString('<title>Page expired · EgHub</title>', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Refresh the page', $html);
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

        // roles and permissions come from the RoleSeeder: a missing driver role or permission key fails the check
        DB::table('roles')->where('name', 'driver')->delete();
        DB::table('permissions')->where('name', 'delivery.drive')->delete();
        $this->assertSame(1, Artisan::call('egmenu:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('FAIL  Roles seeded (driver role) - run php artisan db:seed --class=RoleSeeder', $output);
        $this->assertStringContainsString('missing delivery.drive', $output);
        $this->assertStringContainsString('OK    Migrations ran (driver_locations table)', $output);

        rmdir($public.'/storage');
        rmdir($public);
    }
}
