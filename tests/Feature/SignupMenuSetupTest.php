<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Notifications\ServiceRequested;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** "Let our team enter your menu" at sign-up: the paid menu_setup request, its email and the dashboard tip. */
class SignupMenuSetupTest extends TenantTestCase
{
    private function signup(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'كافيه جديد', 'slug' => 'new-cafe', 'owner_name' => 'Sara', 'email' => 'sara@new.test',
            'phone' => '01066666666', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ];
    }

    public function test_the_form_offers_the_service_and_the_shop_type(): void
    {
        $this->get($this->menuUrl('register'))->assertOk()
            ->assertSee('name="menu_setup"', false)->assertSee('عايز فريق EgMenu يدخّل المنيو بدالي')->assertSee('من 300 جنيه')
            ->assertSee('value="shop"', false)->assertSee('محل');
    }

    public function test_ticked_creates_a_menu_setup_request_and_emails_the_admins(): void
    {
        Notification::fake();
        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egweb.test', 'password' => 'Secret-pass-123']);

        $this->post($this->menuUrl('register'), $this->signup(['menu_setup' => '1', 'business_type' => 'shop']))->assertRedirect();

        $request = ServiceRequest::query()->sole();
        $this->assertSame(['menu_setup', 'Sara', 'كافيه جديد', '01066666666', 'sara@new.test', 'new', 'ar'],
            [$request->service, $request->name, $request->restaurant, $request->phone, $request->email, $request->status, $request->locale]);
        $this->assertStringContainsString('http://egmenu.test/new-cafe', $request->notes);
        Notification::assertSentTo($admin, ServiceRequested::class, fn (ServiceRequested $n) => $n->request->is($request));

        // the owner asked already: no tip on the dashboard home
        $tenant = Tenant::query()->findOrFail('new-cafe');
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-menu-setup-tip', false);
    }

    public function test_unticked_creates_nothing_and_the_dashboard_suggests_the_service(): void
    {
        Notification::fake();
        $this->post($this->menuUrl('register'), $this->signup())->assertRedirect();

        $this->assertSame(0, ServiceRequest::query()->count());
        Notification::assertNothingSent();

        $tenant = Tenant::query()->findOrFail('new-cafe');
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-menu-setup-tip', false)->assertSee('مش فاضي تدخّل المنيو؟ فريقنا يعملها')
            ->assertSee('href="http://egmenu.test/services?service=menu_setup"', false);

        // a request from the services page with the same phone is the same wish: the tip goes away
        ServiceRequest::query()->create(['service' => 'menu_setup', 'name' => 'Sara', 'restaurant' => 'X', 'phone' => '01066666666']);
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-menu-setup-tip', false);
    }

    public function test_a_filled_menu_does_not_need_the_tip(): void
    {
        $tenant = $this->restaurant('busy-cafe');
        for ($i = 1; $i <= 10; $i++) {
            $this->item($tenant, "Item $i");
        }
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-menu-setup-tip', false);
    }
}
