<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Services\Seo\EgwebClients;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** "مطاعم وكافيهات وثقت في EgWeb": EgWeb's own clients on the home page, edited in admin › branding. */
class EgwebClientsTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Admin', 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** @return list<array<string, mixed>> */
    private function logos(int $active, int $hidden = 0): array
    {
        $list = [];
        for ($i = 1; $i <= $active + $hidden; $i++) {
            $list[] = ['key' => "c$i", 'name' => "Client $i", 'path' => "platform/clients/c$i.webp", 'url' => $i === 1 ? 'https://client-1.example' : '',
                'active' => $i <= $active, 'width' => 300, 'height' => 100];
        }

        return $list;
    }

    public function test_the_bundled_set_shows_the_food_brands_under_an_honest_title(): void
    {
        $page = $this->get($this->menuUrl())->assertOk()->assertSee('data-egweb-clients', false)
            ->assertSee('مطاعم وكافيهات بتثق فينا')->assertSee('EgHub منتج من EgWeb', false)
            ->assertSee('src="/img/egweb-clients/gold-sushi-club.webp" alt="Gold Sushi Club" width="480" height="56" loading="lazy"', false)
            ->assertSee('href="https://www.goldsushiclub.com" target="_blank" rel="noopener nofollow"', false)
            ->assertSee('/img/egweb-clients/vasko.webp', false)
            ->assertDontSee('/img/egweb-clients/medic8.webp', false); // listed but hidden until the owner shows it

        foreach (EgwebClients::defaults() as $logo) {
            $this->assertFileExists(public_path($logo['path']));
        }
        // the EgHub subscribers' strip is a different claim and stays away until real places opted in
        $page->assertDontSee('data-site-showcase', false);
    }

    public function test_the_strip_needs_the_minimum_of_active_logos(): void
    {
        EgwebClients::save($this->logos(EgwebClients::MIN_LOGOS - 1, 3));
        $this->get($this->menuUrl())->assertOk()->assertDontSee('data-egweb-clients', false);

        EgwebClients::save($this->logos(EgwebClients::MIN_LOGOS));
        $this->get($this->menuUrl())->assertOk()->assertSee('data-egweb-clients', false)
            ->assertSee('src="/storage/platform/clients/c2.webp"', false)
            ->assertSee('<span class="lp-logo-box">', false); // no link set: no link drawn
    }

    public function test_admin_adds_edits_moves_and_removes_logos_with_audit(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        EgwebClients::save($this->logos(4));

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/branding'))->assertOk()
            ->assertSee('data-egweb-clients-admin', false)->assertSee('Client 4');

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/branding/clients'), [
            'name' => 'Koshary Place', 'url' => 'https://koshary.example', 'active' => 1,
            'image' => UploadedFile::fake()->image('koshary.png', 400, 120),
        ])->assertRedirect()->assertSessionHas('ok');
        $added = collect(EgwebClients::all())->firstWhere('name', 'Koshary Place');
        $this->assertTrue($added['active']);
        $this->assertStringStartsWith('platform/clients/', $added['path']);
        Storage::disk('public')->assertExists($added['path']);
        $this->assertSame('egweb_clients.added', PlatformAuditLog::query()->latest('id')->value('action'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/branding/clients/{$added['key']}"), ['name' => 'Koshary', 'url' => ''])
            ->assertSessionHas('ok');
        $this->assertSame(['Koshary', '', false], array_values(array_intersect_key(EgwebClients::all()[4], array_flip(['name', 'url', 'active']))));

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/branding/clients/{$added['key']}/move"), ['direction' => 'up'])->assertSessionHas('ok');
        $this->assertSame($added['key'], EgwebClients::all()[3]['key']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/branding/clients/c1'), ['name' => 'X', 'url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('url');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/branding/clients/{$added['key']}/delete"))->assertSessionHas('ok');
        $this->assertNull(collect(EgwebClients::all())->firstWhere('key', $added['key']));
        Storage::disk('public')->assertMissing($added['path']);
        $this->assertSame(['egweb_clients.added', 'egweb_clients.updated', 'egweb_clients.moved', 'egweb_clients.removed'],
            PlatformAuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_only_admins_with_the_settings_permission_can_change_logos(): void
    {
        $manager = $this->admin('manager');

        $this->actingAs($manager, 'admin')->post($this->adminUrl('/branding/clients'), [
            'name' => 'Nope', 'image' => UploadedFile::fake()->image('n.png', 200, 80),
        ])->assertForbidden();
        $this->actingAs($manager, 'admin')->post($this->adminUrl('/branding/clients/gold-sushi-club/delete'))->assertForbidden();
        $this->assertCount(count(EgwebClients::DEFAULTS), EgwebClients::all());
    }

    public function test_an_uploaded_svg_is_stored_without_scripts(): void
    {
        Storage::fake('public');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 90" onload="alert(1)"><script>alert(2)</script>'
            .'<a href="javascript:alert(3)"><rect width="300" height="90" fill="#f60"/></a></svg>';

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/branding/clients'), [
            'name' => 'Vector Cafe', 'active' => 1, 'image' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
        ])->assertSessionHas('ok');

        $logo = collect(EgwebClients::all())->firstWhere('name', 'Vector Cafe');
        $this->assertStringEndsWith('.svg', $logo['path']);
        $this->assertSame([300, 90], [$logo['width'], $logo['height']]);
        $stored = Storage::disk('public')->get($logo['path']);
        $this->assertStringContainsString('<rect', $stored);
        foreach (['<script', 'onload', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $stored);
        }
    }

    public function test_the_import_command_seeds_the_list_once(): void
    {
        Storage::fake('public');
        $dir = storage_path('framework/testing/egweb-clients-'.uniqid());
        File::ensureDirectoryExists($dir);
        File::copy(public_path('img/egweb-clients/filetto.webp'), "$dir/filetto.webp");
        File::copy(public_path('img/egweb-clients/medic8.webp'), "$dir/Some Other.webp");

        try {
            $this->artisan('egmenu:import-egweb-clients', ['dir' => $dir])->assertSuccessful();
            $list = EgwebClients::all();
            $this->assertSame(['filetto', 'some-other'], array_column($list, 'key'));
            $this->assertSame(['Filetto', true], [$list[0]['name'], $list[0]['active']]);
            $this->assertSame(['Some Other', false], [$list[1]['name'], $list[1]['active']]);
            Storage::disk('public')->assertExists('platform/clients/filetto.webp');

            // an edit by the admin survives a second run, and nothing is added twice
            EgwebClients::save([['name' => 'Filetto Meats'] + $list[0], $list[1]]);
            $this->artisan('egmenu:import-egweb-clients', ['dir' => $dir])->expectsOutputToContain('0 logo(s) added')->assertSuccessful();
            $this->assertSame(['Filetto Meats', 2], [EgwebClients::all()[0]['name'], count(EgwebClients::all())]);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
