<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Tenant;
use App\Services\Customers\GuestAccount;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The guest's loyalty card as its own app on the phone: a manifest and service worker per card token, the live
 * state the installed card re-reads, the install prompt, the card picture and the wallet buttons.
 */
class CardAppTest extends TenantTestCase
{
    /** A restaurant with a 10-stamp card and a guest holding $stamps; returns the guest's card token. */
    private function guest(Tenant $tenant, string $phone, string $name = 'Sara', int $stamps = 0): string
    {
        return $this->as($tenant, function () use ($phone, $name, $stamps) {
            LoyaltyProgram::query()->firstOrCreate(['title' => 'Coffee card'], [
                'type' => 'visits', 'stamps_required' => 10, 'reward_label' => 'Free coffee', 'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup'],
            ]);
            $card = app(LoyaltyService::class)->enroll($phone, $name);
            LoyaltyCard::query()->whereKey($card->id)->update(['stamps' => $stamps]);

            return (string) Customer::query()->whereKey($card->customer_id)->value('card_token');
        });
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_each_card_installs_as_its_own_app_scoped_to_that_card(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ', 'color_bg' => '#FAFAFA', 'color_secondary' => '#123456']));
        $token = $this->guest($a, '01066666666');
        $other = $this->guest($a, '01077777777', 'Mona');

        $response = $this->get($this->subUrl('cafe-a', "card/$token/manifest.webmanifest"))->assertOk()
            ->assertJsonPath('id', "/card/$token")->assertJsonPath('scope', "/card/$token")->assertJsonPath('start_url', "/card/$token?source=pwa")
            ->assertJsonPath('name', 'كافيه أ · كارتي')->assertJsonPath('short_name', 'كارتي')->assertJsonPath('display', 'standalone')
            ->assertJsonPath('theme_color', '#123456')->assertJsonPath('background_color', '#FAFAFA')
            ->assertJsonPath('icons.0.src', $this->subUrl('cafe-a', 'icon-192.png'))->assertJsonPath('icons.1.src', $this->subUrl('cafe-a', 'icon-512.png'));
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString($other, (string) $response->getContent());
        $this->assertPrivate($response);
        // not the menu's app: a different id from the restaurant's menu manifest
        $this->get($this->subUrl('cafe-a', 'manifest.webmanifest'))->assertJsonPath('id', '/');

        // on the canonical address the card owns /cafe-a/card/{token}
        $this->get($this->pathUrl('cafe-a', "card/$token/manifest.webmanifest"))->assertOk()
            ->assertJsonPath('scope', "/cafe-a/card/$token")->assertJsonPath('start_url', "/cafe-a/card/$token?source=pwa");

        // its service worker: only that card's path, only that card's cache
        $this->get($this->subUrl('cafe-a', "card/$token/sw.js"))->assertOk()
            ->assertHeader('Service-Worker-Allowed', "/card/$token")
            ->assertSee("'egmenu-card-$token-v1'", false)->assertSee('"'.$this->subUrl('cafe-a', "card/$token").'"', false)
            ->assertDontSee($other);
        // the menu's own worker leaves the card pages alone
        $this->get($this->subUrl('cafe-a', 'sw.js'))->assertOk()->assertSee("url.pathname.startsWith(SCOPE + 'card/')", false);
    }

    public function test_a_token_of_another_restaurant_or_an_unknown_one_opens_nothing(): void
    {
        $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->guest($this->restaurant('cafe-c'), '01055555555');
        $tokenOfB = $this->guest($b, '01077777777');

        foreach (["card/$tokenOfB", "card/$tokenOfB/manifest.webmanifest", "card/$tokenOfB/sw.js", "card/$tokenOfB/state", "card/$tokenOfB/image.svg",
            'card/'.str_repeat('a', 24).'/state', 'card/'.str_repeat('a', 24).'/manifest.webmanifest'] as $path) {
            $this->get($this->subUrl('cafe-c', $path))->assertNotFound();
        }
        $this->get($this->subUrl('cafe-b', "card/$tokenOfB/state"))->assertOk();
    }

    public function test_the_state_endpoint_returns_only_that_card_and_is_throttled(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ']));
        $token = $this->guest($a, '01066666666', 'Sara', 3);
        $this->guest($a, '01077777777', 'Mona', 7);

        $response = $this->getJson($this->subUrl('cafe-a', "card/$token/state"))->assertOk()
            ->assertJsonPath('brand', 'كافيه أ')->assertJsonPath('title', 'Coffee card')->assertJsonPath('name', 'Sara')
            ->assertJsonPath('type', 'stamps')->assertJsonPath('stamps', 3)->assertJsonPath('required', 10)
            ->assertJsonPath('url', $this->subUrl('cafe-a', "card/$token"));
        $this->assertPrivate($response);
        $body = (string) $response->getContent();
        foreach (['Mona', '01066666666', '01077777777', 'restaurant'] as $never) {
            $this->assertStringNotContainsString($never, $body);
        }
        // a new stamp changes the version the installed card compares against
        $version = $response->json('version');
        $this->as($a, fn () => LoyaltyCard::query()->whereHas('customer', fn ($q) => $q->where('card_token', $token))->update(['stamps' => 4]));
        $this->getJson($this->subUrl('cafe-a', "card/$token/state"))->assertJsonPath('stamps', 4)->assertJsonMissing(['version' => $version]);

        for ($i = 0; $i < 28; $i++) {
            $this->getJson($this->subUrl('cafe-a', "card/$token/state"))->assertOk();
        }
        $this->getJson($this->subUrl('cafe-a', "card/$token/state"))->assertStatus(429);
    }

    public function test_the_card_page_is_the_card_app_with_the_install_prompt_and_no_wallet_buttons_without_keys(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ']));
        $token = $this->guest($a, '01066666666', 'Sara', 2);

        $response = $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()
            ->assertSee('<link rel="manifest" href="'.$this->subUrl('cafe-a', "card/$token/manifest.webmanifest").'">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="كارتي">', false)
            ->assertSee('serviceWorker.register("'.$this->subUrl('cafe-a', "card/$token/sw.js").'", { scope: "/card/'.$token.'" })', false)
            ->assertDontSee($this->subUrl('cafe-a', 'manifest.webmanifest'), false)
            ->assertSee('data-card-install', false)->assertSee('ضيف الكارت لموبايلك')
            ->assertSee('beforeinstallprompt', false)->assertSee('data-card-sheet', false)->assertSee(__('Choose "Add to Home Screen"'))
            ->assertSee('(display-mode: standalone)', false)
            ->assertSee('احفظ صورة الكارت')->assertSee('href="'.$this->subUrl('cafe-a', "card/$token/image.svg").'"', false)
            ->assertSee('data-state-url="'.$this->subUrl('cafe-a', "card/$token/state").'"', false)
            ->assertSee('visibilitychange', false)
            ->assertDontSee('data-wallet-apple', false)->assertDontSee('data-wallet-google', false)
            ->assertSee('<meta name="robots" content="noindex">', false);
        $this->assertPrivate($response);

        $this->get($this->subUrl('cafe-a', "card/$token/wallet/google"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "card/$token/wallet/apple"))->assertNotFound();
    }

    public function test_the_card_picture_carries_the_card_and_its_qr(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ']));
        $token = $this->guest($a, '01066666666', 'Sara', 4);

        $response = $this->get($this->subUrl('cafe-a', "card/$token/image.svg"))->assertOk()
            ->assertSee('<svg', false)->assertSee('كافيه أ')->assertSee('Coffee card')->assertSee('4 / 10')->assertSee('Sara')
            ->assertSee('href="data:image/svg+xml;base64,', false);
        $this->assertStringStartsWith('image/svg+xml', (string) $response->headers->get('Content-Type'));
        $this->assertPrivate($response);
    }

    public function test_google_wallet_button_shows_and_links_to_the_save_page_once_configured(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'card-app-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/openssl.cnf", "[req]\ndistinguished_name = dn\n[dn]\n");
        $key = openssl_pkey_new(['config' => "$dir/openssl.cnf", 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem, null, ['config' => "$dir/openssl.cnf"]);
        file_put_contents("$dir/sa.json", json_encode(['client_email' => 'w@p.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.wallet.google' => ['issuer_id' => '3388000000012345678', 'service_account' => "$dir/sa.json"]]);
        try {
            $a = $this->restaurant('cafe-a');
            $token = $this->guest($a, '01066666666');

            $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk()
                ->assertSee('data-wallet-google', false)->assertSee(__('Save to Google Wallet'))->assertDontSee('data-wallet-apple', false);
            $redirect = (string) $this->get($this->subUrl('cafe-a', "card/$token/wallet/google"))->assertRedirect()->headers->get('Location');
            $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $redirect);
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function test_the_counter_tells_the_cashier_the_guest_can_add_the_card_to_their_phone(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->guest($a, '01066666666');

        $this->actingAs($this->owner($a))->get($this->dashUrl('/loyalty?q=01066666666'))->assertOk()
            ->assertSee('data-card-link', false)->assertSee('data-card-install-hint', false)
            ->assertSee('الضيف يقدر يضيف الكارت لموبايله من اللينك');
    }

    public function test_the_card_qr_carries_a_scan_code_that_finds_the_guest_at_the_counter_and_opens_nothing_of_theirs(): void
    {
        $a = $this->restaurant('cafe-a');
        $token = $this->guest($a, '01066666666', 'Sara');
        $this->get($this->subUrl('cafe-a', "card/$token"))->assertOk();
        $code = $this->as($a, fn () => Customer::query()->where('card_token', $token)->value('scan_code'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{20}$/', $code);

        // the QR's page: the code for the cashier, no name, no cookie that would open the guest's balance
        $scan = $this->get($this->subUrl('cafe-a', "c/$code"))->assertOk()->assertSee('data-scan-code', false)->assertDontSee('Sara');
        $this->assertNull($scan->getCookie('card_cafe-a'));
        $this->assertPrivate($scan);
        $this->get($this->subUrl('cafe-a', 'c/'.str_repeat('0', 20)))->assertNotFound();

        // the counter finds the guest by the scanned link, the code typed as shown, or an older card's link
        $owner = $this->owner($a);
        foreach ([$this->subUrl('cafe-a', "c/$code"), trim(chunk_split(strtoupper($code), 4, ' ')), $this->subUrl('cafe-a', "card/$token")] as $scanned) {
            $this->actingAs($owner)->get($this->dashUrl('/loyalty?q='.urlencode($scanned)))->assertOk()->assertSee('Sara');
        }

        // a new card link (the old one leaked) gives the card a new code too
        $this->as($a, fn () => GuestAccount::rotate(Customer::query()->where('card_token', $token)->firstOrFail()));
        $this->get($this->subUrl('cafe-a', "c/$code"))->assertNotFound();
    }
}
