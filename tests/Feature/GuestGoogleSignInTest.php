<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TenantTestCase;

/**
 * «الدخول بجوجل» for a restaurant's guests: the menu host → eghub.app/auth/google/start (signed state) → Google →
 * the one fixed callback → a one-time ticket back on the restaurant's host → its usual remembered-guest cookie.
 * Socialite is mocked; no provider is called.
 */
class GuestGoogleSignInTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'google-test-id', 'services.google.client_secret' => 'google-test-secret']);
    }

    /** Google answers with this person; the start only asks for openid, email and profile. */
    private function googleReturns(string $sub = 'g-sub-1', string $email = 'mona@gmail.test', bool $verified = true, string $name = 'Mona Adel'): void
    {
        $identity = (new ProviderUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $sub, 'name' => $name, 'email' => $email]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->with('http://egmenu.test/auth/google/callback')->andReturnSelf();
        $driver->shouldReceive('setScopes')->with(['openid', 'email', 'profile'])->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.test/o/oauth2/auth'));
        $driver->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    /**
     * Taps «الدخول بجوجل» on $slug's subdomain and comes back from Google: the ticket URL and the nonce cookie.
     *
     * @return array{0:string, 1:string}
     */
    private function signInAt(string $slug): array
    {
        $tap = $this->get($this->subUrl($slug, 'me/google'));
        $start = (string) $tap->headers->get('Location');
        $this->assertStringStartsWith('http://egmenu.test/auth/google/start?state=', $start);
        $nonce = (string) $tap->getCookie('gsi_'.$slug)?->getValue();
        $this->assertSame(64, strlen($nonce));

        $this->get($start)->assertRedirect('https://accounts.google.test/o/oauth2/auth');
        $back = $this->get('http://egmenu.test/auth/google/callback?code=abc&state=xyz');
        $ticketUrl = (string) $back->headers->get('Location');
        $this->assertMatchesRegularExpression('#^'.preg_quote($this->subUrl($slug, 'me/google/done'), '#').'\?ticket=[a-f0-9]{64}$#', $ticketUrl);
        $this->assertStringContainsString('no-store', (string) $back->headers->get('Cache-Control'));

        return [$ticketUrl, $nonce];
    }

    private function redeem(string $ticketUrl, ?string $nonce, string $slug): TestResponse
    {
        return $nonce === null ? $this->get($ticketUrl) : $this->withCookie('gsi_'.$slug, $nonce)->get($ticketUrl);
    }

    /** @param array<string, mixed> $overrides */
    private function state(array $overrides = []): string
    {
        return Crypt::encryptString(json_encode($overrides + [
            'p' => 'google', 't' => 'cafe-a', 'r' => 'http://cafe-a.egmenu.test/me/google/done',
            'n' => hash('sha256', 'nonce'), 'e' => now()->addMinutes(5)->getTimestamp(),
        ]));
    }

    private function guests(Tenant $tenant): Collection
    {
        return $this->as($tenant, fn () => Customer::query()->get());
    }

    public function test_the_round_trip_creates_the_guest_in_that_restaurant_only_and_asks_for_the_mobile(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->googleReturns();

        [$ticketUrl, $nonce] = $this->signInAt('cafe-a');
        $done = $this->redeem($ticketUrl, $nonce, 'cafe-a')->assertRedirect($this->subUrl('cafe-a', 'me/phone'))->assertCookieExpired('gsi_cafe-a');

        $mona = $this->guests($a)->sole();
        $this->assertSame(['g-sub-1', 'mona@gmail.test', 'Mona Adel', '', 'google'], [$mona->google_sub, $mona->email, $mona->name, $mona->phone, $mona->source]);
        $this->assertNull($mona->getRawOriginal('phone'));
        $done->assertCookie('card_cafe-a', $mona->card_token);
        $this->assertCount(0, $this->guests($b));

        // the mobile, asked once: saved on this guest, then the menu
        $this->withCookie('card_cafe-a', $mona->card_token)->get($this->subUrl('cafe-a', 'me/phone'))->assertOk()
            ->assertSee('data-account-phone', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->withCookie('card_cafe-a', $mona->card_token)->post($this->subUrl('cafe-a', 'me/phone'), ['phone' => '0123'])
            ->assertSessionHasErrors('phone');
        $this->withCookie('card_cafe-a', $mona->card_token)->post($this->subUrl('cafe-a', 'me/phone'), ['phone' => '+20 101 234 5678'])
            ->assertRedirect($this->subUrl('cafe-a'));
        $this->assertSame('01012345678', $this->as($a, fn () => $mona->fresh()->phone));
        // asked once: a guest with a mobile is not asked again
        $this->withCookie('card_cafe-a', $mona->card_token)->get($this->subUrl('cafe-a', 'me/phone'))->assertRedirect($this->subUrl('cafe-a'));

        // the next sign-in with the same Google account is the same guest, straight to the menu
        [$again, $nonce] = $this->signInAt('cafe-a');
        $this->redeem($again, $nonce, 'cafe-a')->assertRedirect($this->subUrl('cafe-a'))->assertCookie('card_cafe-a', $mona->card_token);
        $this->assertCount(1, $this->guests($a));
    }

    public function test_the_same_google_account_at_two_restaurants_is_two_separate_guests(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        // cafe-b already knows this person by the verified email: linked there, not created again
        $known = $this->as($b, fn () => Customer::query()->create(['phone' => '01099999999', 'name' => 'Mona B', 'email' => 'mona@gmail.test']));
        $this->googleReturns();

        [$ticketA, $nonceA] = $this->signInAt('cafe-a');
        $this->redeem($ticketA, $nonceA, 'cafe-a')->assertRedirect();
        [$ticketB, $nonceB] = $this->signInAt('cafe-b');
        $this->redeem($ticketB, $nonceB, 'cafe-b')->assertRedirect($this->subUrl('cafe-b'))->assertCookie('card_cafe-b', $known->card_token);

        $guestA = $this->guests($a)->sole();
        $guestB = $this->guests($b)->sole();
        $this->assertTrue($guestB->is($known));
        $this->assertSame('g-sub-1', $guestA->google_sub);
        $this->assertSame('g-sub-1', $guestB->google_sub);
        $this->assertNotSame($guestA->card_token, $guestB->card_token);
        // cafe-a's key opens nothing at cafe-b
        $this->get($this->subUrl('cafe-b', 'me/'.$guestA->card_token))->assertNotFound();
    }

    public function test_an_unverified_google_email_is_neither_kept_nor_matched(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Customer::query()->create(['phone' => '01099999999', 'name' => 'Other', 'email' => 'mona@gmail.test']));
        $this->googleReturns(verified: false);

        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->redeem($ticket, $nonce, 'cafe-a')->assertRedirect($this->subUrl('cafe-a', 'me/phone'));

        $created = $this->as($a, fn () => Customer::query()->where('google_sub', 'g-sub-1')->sole());
        $this->assertNull($created->email);
        $this->assertNull($this->as($a, fn () => Customer::query()->where('phone', '01099999999')->value('google_sub')));
    }

    public function test_a_tampered_expired_or_foreign_state_is_refused(): void
    {
        $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');
        $this->googleReturns();
        $start = fn (string $state) => $this->get('http://egmenu.test/auth/google/start?state='.rawurlencode($state));

        $start('not-a-state')->assertStatus(400)->assertSee('data-guest-sign-in-lost', false);
        $start(substr($this->state(), 0, -4).'AAAA')->assertStatus(400);
        $start($this->state(['e' => now()->subMinute()->getTimestamp()]))->assertStatus(400);
        // never back to someone else's host: another site, another restaurant's subdomain or path, another path here
        foreach (['https://evil.test/me/google/done', 'http://cafe-b.egmenu.test/me/google/done', 'http://egmenu.test/cafe-b/me/google/done',
            'http://cafe-a.egmenu.test/me/google/done?next=https://evil.test', 'http://cafe-a.egmenu.test/me', 'http://cafe-a.egmenu.test.evil.test/me/google/done'] as $foreign) {
            $start($this->state(['r' => $foreign]))->assertStatus(400);
        }
        $this->assertNull(session('guest_oauth'));

        // the restaurant's canonical path address is one of its own
        $start($this->state(['r' => 'http://egmenu.test/cafe-a/me/google/done']))->assertRedirect('https://accounts.google.test/o/oauth2/auth');
        // a callback without a started sign-in has nowhere to go back to
        $this->get('http://egmenu.test/auth/google/callback?code=abc&state=xyz');
        $this->get('http://egmenu.test/auth/google/callback?code=abc&state=xyz')->assertStatus(400);
    }

    public function test_the_ticket_is_single_use_bound_to_its_browser_and_restaurant_and_expires(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');
        $this->googleReturns();

        // another browser (no nonce) cannot use it, and it is spent
        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->redeem($ticket, null, 'cafe-a')->assertRedirect($this->subUrl('cafe-a', 'me'))->assertCookieMissing('card_cafe-a')
            ->assertSessionHasErrors('google', errorBag: 'google');
        $this->redeem($ticket, $nonce, 'cafe-a')->assertRedirect($this->subUrl('cafe-a', 'me'))->assertCookieMissing('card_cafe-a');

        // another restaurant's host does not take it
        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->redeem(str_replace('cafe-a.', 'cafe-b.', $ticket), $nonce, 'cafe-b')->assertCookieMissing('card_cafe-b');

        // once, and only for two minutes
        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->redeem($ticket, $nonce, 'cafe-a')->assertCookie('card_cafe-a');
        $this->redeem($ticket, $nonce, 'cafe-a')->assertCookieMissing('card_cafe-a');
        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->travelTo(now()->addSeconds(121));
        $this->redeem($ticket, $nonce, 'cafe-a')->assertCookieMissing('card_cafe-a');

        $this->assertSame(0, GuestSignIn::query()->whereNull('used_at')->where('expires_at', '>', now())->count());
        $this->assertCount(1, $this->guests($a));
    }

    public function test_cancel_and_errors_come_back_to_the_restaurant_with_a_message(): void
    {
        $this->restaurant('cafe-a');
        $this->googleReturns();
        $this->get($this->get($this->subUrl('cafe-a', 'me/google'))->headers->get('Location'));

        $this->get('http://egmenu.test/auth/google/callback?error=access_denied&state=xyz')
            ->assertRedirect($this->subUrl('cafe-a', 'me/google/done').'?error=cancelled');
        $this->get($this->subUrl('cafe-a', 'me/google/done?error=cancelled'))->assertRedirect($this->subUrl('cafe-a', 'me'))
            ->assertSessionHasErrors(['google' => __('You cancelled the Google sign-in. Try again whenever you like, or use another way below.')], errorBag: 'google');
        $this->followingRedirects()->get($this->subUrl('cafe-a', 'me/google/done?error=cancelled'))->assertOk()->assertSee('data-google-error', false)
            ->assertSee(__('You cancelled the Google sign-in. Try again whenever you like, or use another way below.'));
    }

    public function test_the_button_shows_only_when_google_is_configured(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-sign-in-google', false)
            ->assertSee($this->subUrl('cafe-a', 'me/google'), false)->assertSee('data-account-verify', false);

        config(['services.google.client_id' => null]);
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-sign-in-google', false)->assertSee('data-account-verify', false);
        $this->get($this->subUrl('cafe-a', 'me/google'))->assertNotFound();
        $this->get('http://egmenu.test/auth/google/start?state=x')->assertNotFound();
    }

    public function test_an_existing_guests_mobile_is_not_taken_over_but_proving_it_links_google(): void
    {
        $a = $this->restaurant('cafe-a');
        $sara = $this->as($a, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'Sara']));
        $order = $this->as($a, fn () => Order::query()->create([
            'branch_id' => Branch::query()->value('id'), 'number' => 4321, 'type' => 'takeaway', 'status' => 'served', 'source' => 'online',
            'subtotal' => 50, 'total' => 50, 'tracking_token' => random_token(12), 'customer_id' => $sara->id, 'customer_phone' => '01012345678',
        ]));
        $this->googleReturns();
        [$ticket, $nonce] = $this->signInAt('cafe-a');
        $this->redeem($ticket, $nonce, 'cafe-a');
        $google = $this->as($a, fn () => Customer::query()->where('google_sub', 'g-sub-1')->sole());

        $this->withCookie('card_cafe-a', $google->card_token)->from($this->subUrl('cafe-a', 'me/phone'))
            ->post($this->subUrl('cafe-a', 'me/phone'), ['phone' => '01012345678'])->assertRedirect($this->subUrl('cafe-a', 'me/phone'))
            ->assertSessionHasErrors('phone');
        $this->assertSame('', $this->as($a, fn () => $google->fresh()->phone));

        // proving the mobile with a previous order moves the Google sign-in onto that guest
        $this->withCookie('card_cafe-a', $google->card_token)
            ->post($this->subUrl('cafe-a', 'me/verify/order'), ['phone' => '01012345678', 'number' => (string) $order->number])
            ->assertRedirect($this->subUrl('cafe-a', 'me/'.$sara->card_token));
        $this->assertSame(['g-sub-1', 'mona@gmail.test'], $this->as($a, fn () => [$sara->fresh()->google_sub, $sara->fresh()->email]));
        $this->assertCount(1, $this->guests($a));
    }
}
