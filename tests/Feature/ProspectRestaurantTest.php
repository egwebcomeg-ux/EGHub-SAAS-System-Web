<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WelcomeKitReady;
use App\Services\DemoContent;
use App\Services\Settings;
use App\Services\WelcomeKit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TenantTestCase;

/**
 * A restaurant EgWeb makes for a prospect: owner with a temporary password to change, trial dates, tagged sample
 * rows the owner clears in one go, and the welcome kit (signed page, email without the password, WhatsApp link).
 */
class ProspectRestaurantTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->firstOrCreate(['email' => 'admin@egmenu.test'], ['name' => 'Admin', 'password' => 'Secret-pass-123']);
    }

    /** @param  array<string,mixed>  $overrides */
    private function createProspect(array $overrides = []): Tenant
    {
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants'), $overrides + [
            'name' => 'Cafe Nile', 'slug' => 'cafe-nile', 'business_type' => 'cafe', 'owner_name' => 'Hany',
            'email' => 'hany@nile.test', 'phone' => '01012345678', 'plan' => 'full', 'trial_days' => 10, 'demo' => '1',
        ])->assertRedirect($this->adminUrl('/tenants/'.($overrides['slug'] ?? 'cafe-nile')))->assertSessionHasNoErrors();

        return Tenant::query()->findOrFail($overrides['slug'] ?? 'cafe-nile');
    }

    private function kitUrl(Tenant $tenant): string
    {
        return (string) app(WelcomeKit::class)->url($tenant);
    }

    public function test_admin_creates_a_prospect_restaurant_with_a_temporary_password_trial_and_tagged_demo_rows(): void
    {
        Notification::fake();
        $tenant = $this->createProspect();

        $owner = $this->owner($tenant);
        $this->assertTrue($owner->must_change_password);
        $this->assertNotEmpty($owner->temporary_password);
        $this->assertSame('trialing', $tenant->subscription->status);
        $this->assertSame('full', $tenant->subscription->plan->code);
        $this->assertTrue($tenant->subscription->trial_ends_at->between(now()->addDays(10)->subMinute(), now()->addDays(10)->addMinute()));

        $this->as($tenant, function () {
            $ids = DemoContent::ids();
            $this->assertCount(DemoContent::ORDERS, $ids['orders']);
            $this->assertSame(DemoContent::ORDERS, Order::query()->count());
            $this->assertCount(5, $ids['customers']);
            $this->assertTrue(Customer::query()->whereIn('id', $ids['customers'])->get()->every(fn ($c) => str_contains($c->name, DemoContent::MARK)));
            $this->assertCount(2, $ids['loyalty_cards']);
            $this->assertCount(1, $ids['coupons']);
            $this->assertCount(1, $ids['offers']);
            $this->assertCount(2, $ids['tables']);
            $this->assertCount(1, $ids['reservations']);
            $this->assertCount(1, $ids['kb_articles']);
            $this->assertCount(1, $ids['users']);
            $this->assertCount(1, $ids['corporate_contracts']);
            $this->assertFalse(User::query()->find($ids['users'][0])->is_active);
            // the orders spread over the last 7 days
            $this->assertTrue(Order::query()->where('created_at', '<', now()->subDays(5))->exists());
            $this->assertTrue(Order::query()->where('status', 'new')->exists());
        });

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/tenants/cafe-nile'))->assertOk()
            ->assertSee($this->kitUrl($tenant))->assertSee($owner->temporary_password)->assertSee('wa.me/201012345678', false);
    }

    public function test_no_email_gives_a_placeholder_that_is_never_mailed(): void
    {
        Notification::fake();
        $tenant = $this->createProspect(['slug' => 'koshary-x', 'email' => '', 'phone' => '', 'demo' => '0']);

        $owner = $this->owner($tenant);
        $this->assertSame('koshary-x@egmenu.app', $owner->email);
        $this->assertTrue(WelcomeKit::isPlaceholder($owner->email, $tenant));
        $this->as($tenant, fn () => $this->assertFalse(DemoContent::present()));
        Notification::assertNothingSent();
    }

    public function test_the_welcome_email_is_queued_for_a_real_address_and_carries_no_password(): void
    {
        Notification::fake();
        $tenant = $this->createProspect();
        $owner = $this->owner($tenant);

        Notification::assertSentTo($owner, WelcomeKitReady::class, function (WelcomeKitReady $mail) use ($owner) {
            $rendered = (string) $mail->toMail($owner)->render();

            return $mail->welcomeUrl !== '' && str_contains($rendered, 'welcome-kit')
                && ! str_contains($rendered, $owner->temporary_password);
        });
        $this->assertContains(ShouldQueue::class, class_implements(WelcomeKitReady::class));
    }

    public function test_the_welcome_page_is_signed_expires_and_hides_the_password_once_changed(): void
    {
        Notification::fake();
        $tenant = $this->createProspect();
        $other = $this->restaurant('cafe-other');
        $owner = $this->owner($tenant);
        $password = $owner->temporary_password;
        $url = $this->kitUrl($tenant);

        $this->get($url)->assertOk()->assertSee('Cafe Nile')->assertSee($password)->assertSee('hany@nile.test')
            ->assertSee('cafe-nile', false)->assertDontSee('Restaurant cafe-other')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
            // every module of the system in one short line, ticked when the plan has it
            ->assertSee('data-kit-features', false)->assertSee(__('Everything you can do with EgMenu'))
            ->assertSee('data-kit-feature="qr-menu" data-included="1"', false)
            ->assertSee('data-kit-feature="pos" data-included="0"', false);   // operations are Enterprise-only
        $this->assertStringContainsString('no-store', (string) $this->get($url)->headers->get('Cache-Control'));

        // tampered: another restaurant's slug with this signature, or no signature at all
        $this->get(str_replace('/cafe-nile/', '/cafe-other/', $url))->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();

        // after 14 days the link is dead
        $this->travel(WelcomeKit::VALID_DAYS + 1)->days();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // the owner signs in and must change the password first
        $this->post($this->dashUrl('/login'), ['login' => 'hany@nile.test', 'password' => $password])->assertRedirect();
        $this->get($this->dashUrl('/'))->assertRedirect(route('dash.security'));
        $this->get($this->dashUrl('/security'))->assertOk()->assertSee(__('You signed in with a temporary password. Choose your own to open the rest of your dashboard.'));
        $this->post($this->dashUrl('/security/password'), [
            'current_password' => $password, 'password' => 'MyOwnPass-2026', 'password_confirmation' => 'MyOwnPass-2026',
        ])->assertRedirect(route('dash.home'));
        $owner->refresh();
        $this->assertFalse($owner->must_change_password);
        $this->assertNull($owner->temporary_password);
        $this->get($this->dashUrl('/'))->assertOk();

        $this->get($url)->assertOk()->assertDontSee($password)->assertSee(__('The password was changed'));
        $this->assertNotNull($other);
    }

    public function test_a_new_kit_makes_the_old_link_fail(): void
    {
        Notification::fake();
        $tenant = $this->createProspect(['demo' => '0']);
        $old = $this->kitUrl($tenant);
        $oldPassword = $this->owner($tenant)->temporary_password;

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-nile/welcome-kit'))->assertRedirect();

        $this->get($old)->assertForbidden();
        $fresh = $this->kitUrl($tenant);
        $this->assertNotSame($old, $fresh);
        $newPassword = $this->owner($tenant)->temporary_password;
        $this->assertNotSame($oldPassword, $newPassword);
        $this->get($fresh)->assertOk()->assertSee($newPassword);
    }

    public function test_the_whatsapp_link_carries_the_page_but_not_the_password(): void
    {
        Notification::fake();
        $tenant = $this->createProspect(['demo' => '0']);
        $owner = $this->owner($tenant);

        $link = (string) app(WelcomeKit::class)->whatsappUrl($tenant, $owner);
        $this->assertStringStartsWith('https://wa.me/201012345678?text=', $link);
        $text = rawurldecode(substr($link, strpos($link, '=') + 1));
        $this->assertStringContainsString($this->kitUrl($tenant), $text);
        $this->assertStringNotContainsString($owner->temporary_password, $text);
    }

    public function test_the_owner_clears_only_the_demo_rows_and_another_restaurant_is_untouched(): void
    {
        Notification::fake();
        $tenant = $this->createProspect();
        $owner = $this->owner($tenant);
        $owner->forceFill(['password' => 'MyOwnPass-2026'])->save();
        $other = $this->restaurant('cafe-other', 'full');
        $this->as($other, fn () => Customer::query()->create(['name' => 'Real guest', 'phone' => '01000000001']));

        // a real order and a real coupon of the prospect, made after the sample rows
        $real = $this->as($tenant, function () {
            Coupon::query()->create(['code' => 'REAL5', 'type' => 'percent', 'value' => 5, 'source' => 'voucher']);

            return Order::query()->create(['branch_id' => Branch::query()->value('id'), 'number' => 99, 'total' => 50, 'status' => 'served']);
        });

        $this->actingAs($owner, 'web')->get($this->dashUrl('/'))->assertOk()->assertSee(__('This is sample data — clear it when you start.'))->assertSee(__('Clear the sample data'));
        $this->actingAs($owner, 'web')->post($this->dashUrl('/demo/clear'), ['confirm' => '1'])->assertRedirect(route('dash.home'));

        $this->as($tenant, function () use ($real) {
            $this->assertFalse(DemoContent::present());
            $this->assertSame([$real->id], Order::query()->pluck('id')->all());
            $this->assertSame(['REAL5'], Coupon::query()->pluck('code')->all());
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, LoyaltyCard::query()->count());
            $this->assertSame(1, User::query()->count());
            $this->assertTrue(AuditLog::query()->where('action', 'demo.cleared')->exists());
        });
        $this->as($other, fn () => $this->assertSame(1, Customer::query()->count()));
        $this->actingAs($owner, 'web')->get($this->dashUrl('/'))->assertOk()->assertDontSee(__('Clear the sample data'));
    }

    public function test_only_the_owner_may_clear_and_demo_never_seeds_a_place_with_orders(): void
    {
        Notification::fake();
        $tenant = $this->createProspect();
        $manager = $this->staff($tenant, 'manager', 'manager@nile.test');

        $this->actingAs($manager, 'web')->post($this->dashUrl('/demo/clear'), ['confirm' => '1'])->assertForbidden();
        $this->as($tenant, fn () => $this->assertTrue(DemoContent::present()));

        $busy = $this->restaurant('cafe-busy', 'full');
        $this->as($busy, fn () => Order::query()->create(['branch_id' => Branch::query()->value('id'), 'number' => 1, 'total' => 10]));
        $this->assertNull(app(DemoContent::class)->seed($busy));
        $this->as($busy, function () {
            $this->assertSame(1, Order::query()->count());
            $this->assertSame('', Settings::get('demo_content'));
        });
    }

    public function test_the_welcome_route_url_is_signed_on_the_dashboard_host(): void
    {
        Notification::fake();
        $tenant = $this->createProspect(['demo' => '0']);
        $url = $this->kitUrl($tenant);

        $this->assertStringStartsWith($this->dashUrl('/welcome-kit/cafe-nile/'), $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }
}
