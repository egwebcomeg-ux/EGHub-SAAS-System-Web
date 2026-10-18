<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\Testimonial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Real owner quotes: managed by platform admins, published only with consent, hidden on the home page while none. */
class TestimonialsTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'EgWeb', 'email' => 'quotes@egmenu.test', 'password' => bcrypt('Secret-pass-123')]);
    }

    public function test_only_platform_admins_manage_them_and_consent_is_required_to_publish(): void
    {
        Storage::fake('public');
        $this->get($this->adminUrl('/testimonials'))->assertRedirect();
        $admin = $this->admin();
        $this->restaurant('cafe-a');

        // asked to publish without consent: saved, but stays hidden
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/testimonials'), [
            'name' => 'أحمد', 'quote' => 'الطلبات بقت أسرع', 'is_published' => 1, 'tenant_id' => 'cafe-a',
        ])->assertRedirect();
        $quote = Testimonial::query()->firstOrFail();
        $this->assertFalse($quote->isLive());
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/testimonials/{$quote->id}/publish"))->assertSessionHasErrors('consent');
        $this->assertFalse($quote->fresh()->is_published);

        // a restaurant link must be a real restaurant
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/testimonials/{$quote->id}"), ['name' => 'أحمد', 'quote' => 'x', 'tenant_id' => 'nope'])
            ->assertSessionHasErrors('tenant_id');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/testimonials/{$quote->id}"), [
            'name' => 'أحمد', 'role' => 'صاحب كافيه', 'quote' => 'الطلبات بقت أسرع', 'consent' => 1, 'is_published' => 1, 'rating' => 5,
            'tenant_id' => 'cafe-a', 'photo' => UploadedFile::fake()->image('ahmed.jpg', 600, 600),
        ])->assertRedirect();
        $quote->refresh();
        $this->assertTrue($quote->isLive());
        $this->assertNotNull($quote->consent_at);
        $this->assertStringStartsWith('platform/testimonials/', $quote->photo);
        Storage::disk('public')->assertExists($quote->photo);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/testimonials'))->assertOk()->assertSee('أحمد')->assertSee('data-testimonial', false);

        // withdrawing consent takes it down
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/testimonials/{$quote->id}"), ['name' => 'أحمد', 'quote' => 'الطلبات بقت أسرع', 'is_published' => 1])->assertRedirect();
        $this->assertFalse($quote->fresh()->isLive());

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/testimonials/{$quote->id}/delete"))->assertRedirect();
        $this->assertSame(0, Testimonial::query()->count());
    }

    public function test_the_home_page_shows_only_live_quotes_and_hides_the_section_when_none(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertDontSee('data-testimonials', false);

        $this->restaurant('cafe-a');
        Testimonial::query()->create(['name' => 'منى', 'role' => 'صاحبة مطعم', 'quote' => 'المنيو بقى أوضح للزباين', 'tenant_id' => 'cafe-a',
            'consent_at' => now(), 'is_published' => true, 'rating' => 4]);
        Testimonial::query()->create(['name' => 'Hidden', 'quote' => 'draft quote', 'consent_at' => now(), 'is_published' => false]);
        Testimonial::query()->create(['name' => 'NoConsent', 'quote' => 'no consent quote', 'is_published' => true]);

        $this->get($this->menuUrl())->assertOk()->assertSee('data-testimonials', false)
            ->assertSee('المنيو بقى أوضح للزباين')->assertSee('صاحبة مطعم')->assertSee(Tenant::pathUrl('cafe-a'), false)
            ->assertDontSee('draft quote')->assertDontSee('no consent quote');
    }
}
