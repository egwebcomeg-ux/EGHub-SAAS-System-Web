<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Tests\TenantTestCase;

/**
 * Areas whose pages are tabs (App\Support\AreaTabs): one Corporate app for the contracts, the company visits, the
 * redeem screen and the vouchers report, and the reports with the Z report, the vouchers report and the ratings.
 * Each tab follows its own permission and plan feature, and every old URL still opens.
 */
class AreaTabsTest extends TenantTestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'enterprise');
    }

    /** @return list<string> the routes of the tiles on the home page */
    private function tiles(User $user): array
    {
        preg_match_all('/data-app="([a-z.]+)"/', $this->actingAs($user)->get($this->dashUrl('/'))->assertOk()->getContent(), $m);

        return $m[1];
    }

    /** @return list<string> the keys of the tabs of $area on the page */
    private function tabs(string $html, string $area): array
    {
        if (! preg_match('~<nav [^>]*data-area-tabs='.$area.'[^>]*>(.*?)</nav>~s', $html, $nav)) {
            return [];
        }
        preg_match_all('/data-tab="([a-z]+)"/', $nav[1], $m);

        return $m[1];
    }

    private function railActive(string $html, string $route): bool
    {
        return (bool) preg_match('#\s+aria-current="page"\s+data-rail-item="'.preg_quote($route, '#').'"#', $html);
    }

    /** A staff member with these permissions only. */
    private function withOnly(array $permissions, string $email): User
    {
        $user = $this->staff($this->tenant, 'waiter', $email);
        $this->as($this->tenant, function () use ($user, $permissions) {
            $user->syncRoles([]);
            $user->syncPermissions($permissions);
        });

        return $user->fresh();
    }

    public function test_one_corporate_app_opens_the_tabs_each_user_may_use(): void
    {
        $owner = $this->owner($this->tenant);
        $tiles = $this->tiles($owner);
        $this->assertContains('dash.corporate', $tiles);
        $this->assertNotContains('dash.corporate.bookings', $tiles);
        $this->assertNotContains('dash.redeem', $tiles);

        $html = $this->actingAs($owner)->get($this->dashUrl('/corporate'))->assertOk()->getContent();
        $this->assertSame(['contracts', 'visits', 'redeem', 'report'], $this->tabs($html, 'corporate'));
        // every tab is still its own page, and each one is the Corporate app
        foreach (['/corporate/bookings', '/vouchers/redeem', '/corporate/report'] as $path) {
            $this->assertTrue($this->railActive($this->actingAs($owner)->get($this->dashUrl($path))->assertOk()->getContent(), 'dash.corporate'), $path);
        }

        // a cashier redeems only: the app opens the redeem screen and is named after it, with no tabs to switch
        $cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->assertContains('dash.redeem', $this->tiles($cashier));
        $this->assertNotContains('dash.corporate', $this->tiles($cashier));
        $html = $this->actingAs($cashier)->get($this->dashUrl('/vouchers/redeem'))->assertOk()->assertSee('صرف فاوتشرات الشركات')->getContent();
        $this->assertSame([], $this->tabs($html, 'corporate'));
        $this->assertTrue($this->railActive($html, 'dash.redeem'));

        // staff who take bookings enter the company visits, and see the report too when they may read reports
        $host = $this->withOnly(['reservations.manage'], 'host@cafe-a.test');
        $this->assertContains('dash.corporate.bookings', $this->tiles($host));
        $this->actingAs($host)->get($this->dashUrl('/corporate'))->assertForbidden();
        $analyst = $this->withOnly(['reservations.manage', 'reports.view'], 'analyst@cafe-a.test');
        $html = $this->actingAs($analyst)->get($this->dashUrl('/corporate/report'))->assertOk()->getContent();
        $this->assertSame(['visits', 'report'], $this->tabs($html, 'corporate'));
        $this->assertTrue($this->railActive($html, 'dash.corporate.bookings'));

        // a plan without corporate vouchers has no Corporate app at all
        $pro = $this->restaurant('cafe-pro', 'pro');
        preg_match_all('/data-app="([a-z.]+)"/', $this->actingAs($this->owner($pro))->get($this->dashUrl('/'))->getContent(), $m);
        $this->assertSame([], array_values(array_filter($m[1], fn (string $route) => str_starts_with($route, 'dash.corporate') || $route === 'dash.redeem')));
    }

    public function test_the_reports_have_the_z_report_the_vouchers_report_and_the_ratings_as_tabs(): void
    {
        $owner = $this->owner($this->tenant);
        $html = $this->actingAs($owner)->get($this->dashUrl('/reports'))->assertOk()
            ->assertSee(route('dash.zreport', ['in' => 'reports'], false), false)->getContent();
        $this->assertSame(['sales', 'zreport', 'corporate', 'feedback'], $this->tabs($html, 'reports'));

        // opened from the reports, the Z report and the vouchers report show the reports' tabs and count as Reports
        $html = $this->actingAs($owner)->get($this->dashUrl('/pos/z-report?in=reports'))->assertOk()
            ->assertSee('<input type="hidden" name="in" value="reports">', false)->getContent();
        $this->assertSame(['sales', 'zreport', 'corporate', 'feedback'], $this->tabs($html, 'reports'));
        $this->assertTrue($this->railActive($html, 'dash.reports'));
        $html = $this->actingAs($owner)->get($this->dashUrl('/corporate/report?in=reports'))->assertOk()->getContent();
        $this->assertSame(['sales', 'zreport', 'corporate', 'feedback'], $this->tabs($html, 'reports'));
        $this->assertSame([], $this->tabs($html, 'corporate'));

        // their old URLs stay in their own apps
        $html = $this->actingAs($owner)->get($this->dashUrl('/pos/z-report'))->assertOk()->getContent();
        $this->assertSame([], $this->tabs($html, 'reports'));
        $this->assertTrue($this->railActive($html, 'dash.pos'));
        $this->assertSame(['contracts', 'visits', 'redeem', 'report'], $this->tabs($this->actingAs($owner)->get($this->dashUrl('/corporate/report'))->getContent(), 'corporate'));

        // the ratings are a tab of the reports, and keep their own entry for whoever sees the guests but not the reports
        $html = $this->actingAs($owner)->get($this->dashUrl('/feedback'))->assertOk()->getContent();
        $this->assertSame(['sales', 'zreport', 'corporate', 'feedback'], $this->tabs($html, 'reports'));
        $this->assertTrue($this->railActive($html, 'dash.reports'));
        $this->assertNotContains('dash.feedback', $this->tiles($owner));
        $cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->assertContains('dash.feedback', $this->tiles($cashier));
        $this->assertNotContains('dash.reports', $this->tiles($cashier));
        // the cashier closes the day (shifts.manage): the Z report is the other tab there
        $this->assertSame(['zreport', 'feedback'], $this->tabs($this->actingAs($cashier)->get($this->dashUrl('/feedback'))->assertOk()->getContent(), 'reports'));
        $this->assertTrue($this->railActive($this->actingAs($cashier)->get($this->dashUrl('/pos/z-report?in=reports'))->assertOk()->getContent(), 'dash.feedback'));

        // each tab needs its plan feature: no POS, no Z report tab
        $complete = $this->restaurant('cafe-c', 'full');
        $html = $this->actingAs($this->owner($complete))->get($this->dashUrl('/reports'))->assertOk()->getContent();
        $this->assertSame(['sales', 'corporate', 'feedback'], $this->tabs($html, 'reports'));
    }
}
