<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Notifications\ServiceRequested;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** eghub.app/services: EgWeb's one-time services, the request form and the admin list. */
class ServicesPageTest extends TenantTestCase
{
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'service' => 'print', 'name' => 'Mona', 'restaurant' => 'Rosto Cafe', 'phone' => '01012345678',
            'email' => 'mona@rosto.test', 'quantity' => 20, 'notes' => 'A6 stands for 20 tables',
        ];
    }

    public function test_page_renders_in_arabic_and_english_and_is_linked_from_the_site(): void
    {
        $this->get($this->menuUrl('services'))->assertOk()
            ->assertSee('إدخال المنيو وتجهيزه')->assertSee('300')->assertSee('ستاند QR أكريليك للترابيزة')
            ->assertSee('egweb.com.eg')->assertSee('name="service"', false)
            ->assertSee('<link rel="canonical" href="http://egmenu.test/services">', false);
        $this->get($this->menuUrl('services?lang=en'))->assertOk()
            ->assertSee('Menu setup & data entry')->assertSee('Acrylic QR table stand')->assertSee('Request this service');

        // header after Pricing, and the footer
        $home = $this->get($this->menuUrl())->assertOk()->getContent();
        $this->assertSame(3, substr_count($home, 'href="http://egmenu.test/services"'));
        $this->assertNotNull(Tenant::slugError('services'));
    }

    public function test_a_request_is_stored_and_the_admins_are_emailed(): void
    {
        Notification::fake();
        PlatformSetting::put('support_whatsapp', '01099999999');
        $admins = collect([
            PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egweb.test', 'password' => 'Secret-pass-123']),
            PlatformAdmin::query()->create(['name' => 'B', 'email' => 'b@egweb.test', 'password' => 'Secret-pass-123']),
        ]);

        $this->post($this->menuUrl('services'), $this->valid(['phone' => '+20 101 234 5678']))
            ->assertRedirect('http://egmenu.test/services#request');

        $request = ServiceRequest::query()->sole();
        $this->assertSame(['print', 'Rosto Cafe', '01012345678', 20, 'new'], [$request->service, $request->restaurant, $request->phone, $request->quantity, $request->status]);
        Notification::assertSentTo($admins, ServiceRequested::class, fn (ServiceRequested $n) => $n->request->is($request));
        $mail = (new ServiceRequested($request))->toMail($admins[0]);
        $this->assertStringContainsString('Rosto Cafe', $mail->subject);

        // thank-you with the support WhatsApp
        $this->withSession(['service_sent' => 'x'])->get($this->menuUrl('services'))->assertSee('data-service-sent', false)->assertSee('wa.me/201099999999', false);
    }

    public function test_validation_and_honeypot(): void
    {
        Notification::fake();
        $this->post($this->menuUrl('services'), $this->valid(['service' => 'cake']))->assertSessionHasErrors('service');
        $this->post($this->menuUrl('services'), $this->valid(['name' => '', 'restaurant' => '']))->assertSessionHasErrors(['name', 'restaurant']);
        $this->post($this->menuUrl('services'), $this->valid(['phone' => '12345']))->assertSessionHasErrors('phone');
        $this->post($this->menuUrl('services'), $this->valid(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->post($this->menuUrl('services'), $this->valid(['website' => 'http://spam.test']))->assertSessionHasErrors('website');
        $this->assertSame(0, ServiceRequest::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_form_is_throttled(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->menuUrl('services'), $this->valid())->assertRedirect();
        }
        $this->post($this->menuUrl('services'), $this->valid())->assertStatus(429);
        $this->assertSame(5, ServiceRequest::query()->count());
    }

    public function test_admins_follow_requests_up(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egweb.test', 'password' => 'Secret-pass-123']);
        $request = ServiceRequest::query()->create(['service' => 'training', 'name' => 'Mona', 'restaurant' => 'Sofra', 'phone' => '01012345678']);

        $this->get($this->adminUrl('/services'))->assertRedirect();
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/services'))->assertOk()->assertSee('Sofra')->assertSee('جلسة تشغيل وتدريب');
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/services/{$request->id}"), ['status' => 'contacted'])->assertSessionHasNoErrors();
        $this->assertSame('contacted', $request->fresh()->status);
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/services/{$request->id}"), ['status' => 'lost'])->assertSessionHasErrors('status');
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/services?status=done'))->assertOk()->assertDontSee('Sofra');
    }
}
