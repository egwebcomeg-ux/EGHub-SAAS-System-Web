<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Http\Controllers\Dashboard\TvPairingController;
use App\Models\Invoice;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TvScreen;
use App\Services\Screens\ScreenPairing;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TenantTestCase;

/** EgHub screens: a TV shows a 6-digit code on eghub.app/screen, the owner types it, the TV gets its device token. */
class ScreenPairingTest extends TenantTestCase
{
    /** @return array{code: string, secret: string} */
    private function startPairing(): array
    {
        $response = $this->postJson($this->menuUrl('screen/pair'))->assertOk()->assertJsonStructure(['code', 'secret', 'expires_in', 'qr', 'link']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('code'));

        return ['code' => $response->json('code'), 'secret' => $response->json('secret')];
    }

    private function screen(Tenant $tenant, string $name = 'Counter'): TvScreen
    {
        return $this->as($tenant, fn () => TvScreen::query()->create(['name' => $name, 'token' => random_token(10)]));
    }

    private function pairState(string $secret)
    {
        return $this->getJson($this->menuUrl('screen/pair/status'), ['X-Pair-Secret' => $secret])->assertOk();
    }

    public function test_a_typed_code_pairs_the_tv_once_and_the_token_opens_its_playlist(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $this->item($cafe, 'Latte', 60);
        $screen = $this->screen($cafe);
        $pair = $this->startPairing();
        $this->pairState($pair['secret'])->assertJson(['state' => 'waiting']);

        $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => substr($pair['code'], 0, 3).' '.substr($pair['code'], 3), 'screen_id' => $screen->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('dash.tv.edit', $screen));

        $token = $this->pairState($pair['secret'])->assertJson(['state' => 'paired'])->assertCookie(ScreenPairing::COOKIE)->json('token');
        $this->assertSame(64, strlen($token));
        // only a hash of the token is stored
        $stored = TvScreen::query()->withoutGlobalScopes()->find($screen->id);
        $this->assertSame(hash('sha256', $token), $stored->device_token_hash);
        $this->assertNotNull($stored->paired_at);
        // the token is handed over once
        $this->pairState($pair['secret'])->assertJson(['state' => 'used'])->assertJsonMissingPath('token');

        $this->getJson($this->menuUrl('screen/playlist'), ['X-Screen-Token' => $token])->assertOk()
            ->assertJsonPath('screen.id', $screen->id)->assertJsonPath('slides.0.type', 'menu')
            ->assertJsonPath('slides.0.categories.0.items.0.name', 'Latte');

        // the same code cannot pair a second screen
        $other = $this->screen($cafe, 'Door');
        $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $other->id])
            ->assertSessionHasErrors(['code' => __('This code is wrong or expired. Check the number on the TV (it changes every 10 minutes).')]);
        $this->assertNull(TvScreen::query()->withoutGlobalScopes()->find($other->id)->device_token_hash);
    }

    public function test_a_code_expires_after_ten_minutes(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $pair = $this->startPairing();

        $this->travel(ScreenPairing::CODE_MINUTES * 60 + 1)->seconds();

        $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $screen->id])
            ->assertSessionHasErrors('code');
        $this->pairState($pair['secret'])->assertJson(['state' => 'expired']);
        $this->assertFalse(TvScreen::query()->withoutGlobalScopes()->find($screen->id)->isPaired());
    }

    public function test_wrong_codes_are_limited_per_restaurant(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $pair = $this->startPairing();
        $wrong = $pair['code'] === '000000' ? '111111' : '000000';
        RateLimiter::clear('tv-pair-failed:cafe-a');

        for ($i = 0; $i < TvPairingController::FAILED_ATTEMPTS; $i++) {
            $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => $wrong, 'screen_id' => $screen->id]);
        }
        // even the right code is refused now
        $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $screen->id])
            ->assertSessionHasErrors(['code' => __('Too many wrong codes. Try again in :minutes minutes.', ['minutes' => 10])]);
        $this->assertFalse(TvScreen::query()->withoutGlobalScopes()->find($screen->id)->isPaired());
    }

    public function test_the_device_side_asks_for_codes_a_limited_number_of_times(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($this->menuUrl('screen/pair'))->assertOk();
        }
        $this->postJson($this->menuUrl('screen/pair'))->assertTooManyRequests();
    }

    public function test_another_restaurants_screen_cannot_be_picked(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $theirs = $this->screen($this->restaurant('cafe-b', 'business'));
        $pair = $this->startPairing();

        $this->actingAs($this->owner($cafe))->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $theirs->id])
            ->assertSessionHasErrors('screen_id');
        $this->assertFalse(TvScreen::query()->withoutGlobalScopes()->find($theirs->id)->isPaired());
        $this->pairState($pair['secret'])->assertJson(['state' => 'waiting']);
    }

    public function test_pairing_stops_at_the_screens_the_restaurant_pays_for_and_an_add_on_raises_it(): void
    {
        // Complete includes one screen
        $cafe = $this->restaurant('cafe-a', 'full');
        $first = $this->screen($cafe, 'One');
        $second = $this->screen($cafe, 'Two');
        $owner = $this->owner($cafe);
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $this->startPairing()['code'], 'screen_id' => $first->id])->assertSessionHasNoErrors();

        $pair = $this->startPairing();
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $second->id])
            ->assertSessionHasErrors(['code' => __('You use all the screens in your subscription (:n). Add a screen to pair another one.', ['n' => 1])]);
        // a new screen is refused too
        $this->actingAs($owner)->post($this->dashUrl('/tv-screens'), ['name' => 'Three', 'layout' => 'all', 'columns' => 3, 'rotate_seconds' => 10, 'theme' => 'dark'])
            ->assertSessionHasErrors('screens');

        // two screens bought: billed per screen, then two more may be paired
        $this->actingAs($owner)->post($this->dashUrl('/billing/addons/screens'), ['quantity' => 2])->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', 'cafe-a')->where('kind', 'addon')->latest('id')->firstOrFail();
        $this->assertSame(['addon' => 'screens', 'quantity' => 2], $invoice->meta);
        // two screens at the per-screen price, prorated to the end of the paid month
        $this->assertStringContainsString('× 2', $invoice->lines[0]['label']);
        $this->assertGreaterThan(150 * 1.8, (float) $invoice->total);
        app(Billing::class)->markPaid($invoice, 'instapay', 'ref', (float) $invoice->total, 'test-screens');

        $this->actingAs($owner)->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertTrue(TvScreen::query()->withoutGlobalScopes()->find($second->id)->isPaired());
    }

    public function test_a_screen_add_on_switches_tv_screens_on_for_a_plan_without_them(): void
    {
        $cafe = $this->restaurant('cafe-free', 'free');
        $this->actingAs($this->owner($cafe))->get($this->dashUrl('/tv-screens/pair'))->assertForbidden();

        app(Billing::class)->setAddon($cafe, 'screens', 1);

        $this->actingAs($this->owner($cafe))->get($this->dashUrl('/tv-screens/pair'))->assertOk()->assertSee('data-tv-limit', false);
    }

    public function test_unpairing_and_pairing_again_retire_the_old_token(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $token = $this->as($cafe, fn () => $screen->issueDeviceToken());
        $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $token])->assertOk();

        $this->actingAs($this->owner($cafe))->post($this->dashUrl("/tv-screens/{$screen->id}/unpair"))->assertRedirect();

        $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $token])->assertUnauthorized()->assertJson(['state' => 'unpaired']);
        $this->getJson($this->menuUrl('screen/playlist'))->assertUnauthorized();
    }

    public function test_a_rotated_token_replaces_the_old_one(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $old = $this->as($cafe, fn () => $screen->issueDeviceToken());

        $new = $this->postJson($this->menuUrl('screen/rotate'), [], ['X-Screen-Token' => $old])->assertOk()->json('token');

        $this->assertNotSame($old, $new);
        $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $old])->assertUnauthorized();
        $this->getJson($this->menuUrl('screen/version'), ['X-Screen-Token' => $new])->assertOk();
    }

    public function test_support_signed_in_from_the_admin_cannot_pair(): void
    {
        $cafe = $this->restaurant('cafe-a', 'business');
        $screen = $this->screen($cafe);
        $pair = $this->startPairing();

        $admin = PlatformAdmin::query()->create(['name' => 'Support', 'email' => 'support@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
        $this->get($this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/{$cafe->id}/support-access"))->headers->get('Location'));

        $this->post($this->dashUrl('/tv-screens/pair'), ['code' => $pair['code'], 'screen_id' => $screen->id])
            ->assertSessionHasErrors('support_access');
        $this->assertFalse(TvScreen::query()->withoutGlobalScopes()->find($screen->id)->isPaired());
    }
}
