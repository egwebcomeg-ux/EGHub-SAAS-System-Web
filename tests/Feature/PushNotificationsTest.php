<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/** Push notifications to the EgHub restaurant / waiter apps: phone tokens, each user's choice, FCM HTTP v1. */
class PushNotificationsTest extends TenantTestCase
{
    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/eghub-test/messages:send';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?string $dir = null;

    private mixed $key = null;

    protected function tearDown(): void
    {
        if ($this->dir) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** A service account of our own (PHP on Windows has no default openssl.cnf, so a minimal one is written too). */
    private function configureFcm(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fcm-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/openssl.cnf', "[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $this->dir.'/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $this->key = openssl_pkey_new($options);
        openssl_pkey_export($this->key, $pem, null, $options);
        file_put_contents($this->dir.'/sa.json', json_encode([
            'type' => 'service_account', 'client_email' => 'push@eghub-test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => self::TOKEN_URL,
        ]));
        config(['services.fcm.project_id' => 'eghub-test', 'services.fcm.credentials' => $this->dir.'/sa.json']);
    }

    private function fakeFcm(array $deadTokens = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ya29.test-access', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            self::SEND_URL => function (Request $request) use ($deadTokens) {
                return in_array($request['message']['token'], $deadTokens, true)
                    ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                    : Http::response(['name' => 'projects/eghub-test/messages/1']);
            },
        ]);
    }

    private function register(User $user, string $token, string $app = 'restaurant')
    {
        return $this->actingAs($user)->postJson($this->dashUrl('/devices/push-token'), [
            'token' => $token, 'app' => $app, 'platform' => 'android', 'version' => '1.0.0', 'device' => 'Samsung A54',
        ]);
    }

    private function onlineOrder(Tenant $tenant, string $source = 'online', string $type = 'takeaway'): Order
    {
        $item = $this->item($tenant, 'Pizza', 100);

        return $this->as($tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
            'source' => $source, 'type' => $type,
        ], Branch::query()->first(), $type === 'dine_in' ? DiningTable::query()->firstOrCreate(['branch_id' => Branch::query()->value('id'), 'name' => '5']) : null));
    }

    private function token(string $suffix): string
    {
        return 'fcm-token-'.str_repeat('x', 40).'-'.$suffix;
    }

    public function test_the_app_registers_its_token_for_the_signed_in_user_and_a_phone_moves_with_its_new_account(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $token = $this->token('shared');

        $this->register($this->owner($a), $token)->assertOk();
        $this->register($this->owner($a), $token)->assertOk(); // every page load refreshes the same row
        $this->assertSame(1, DeviceToken::query()->withoutGlobalScopes()->count());

        // the same phone signs in to another restaurant: the token leaves cafe-a
        $this->register($this->owner($b), $token)->assertOk();
        $row = DeviceToken::query()->withoutGlobalScopes()->sole();
        $this->assertSame($b->id, $row->tenant_id);
        $this->assertSame($this->owner($b)->id, $row->user_id);
        $this->assertSame('Samsung A54', $row->device);
        $this->assertSame(0, $this->as($a, fn () => DeviceToken::query()->count()));

        $this->register($this->owner($b), $token, 'browser')->assertUnprocessable()->assertJsonValidationErrors('app');
        $this->actingAs($this->owner($b))->postJson($this->dashUrl('/devices/push-token/delete'), ['token' => $token])->assertOk();
        $this->assertSame(0, DeviceToken::query()->withoutGlobalScopes()->count());
    }

    public function test_a_guest_cannot_register_a_token(): void
    {
        $this->postJson($this->dashUrl('/devices/push-token'), ['token' => $this->token('x'), 'app' => 'restaurant'])->assertUnauthorized();
    }

    public function test_a_user_sees_and_removes_only_their_own_phones(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->register($this->owner($a), $this->token('a'))->assertOk();
        $this->register($this->owner($b), $this->token('b'))->assertOk();
        $phoneA = DeviceToken::query()->withoutGlobalScopes()->where('tenant_id', $a->id)->sole();

        $this->actingAs($this->owner($b))->get($this->dashUrl('/notifications/push'))->assertOk()->assertSee('Samsung A54')->assertSeeText('طلب أونلاين أو كول سنتر جديد');
        $this->actingAs($this->owner($b))->post($this->dashUrl("/notifications/push/devices/{$phoneA->id}/delete"))->assertRedirect();
        $this->assertTrue(DeviceToken::query()->withoutGlobalScopes()->whereKey($phoneA->id)->exists());

        $this->actingAs($this->owner($a))->post($this->dashUrl("/notifications/push/devices/{$phoneA->id}/delete"))->assertRedirect();
        $this->assertFalse(DeviceToken::query()->withoutGlobalScopes()->whereKey($phoneA->id)->exists());
    }

    public function test_each_user_picks_their_notifications_among_the_ones_their_role_allows(): void
    {
        $a = $this->restaurant('cafe-a');
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');

        $this->actingAs($waiter)->post($this->dashUrl('/notifications/push'), ['events' => ['reservation_new']])->assertSessionHasErrors('events.0');
        $this->actingAs($waiter)->post($this->dashUrl('/notifications/push'), ['events' => ['order_new']])->assertSessionHasNoErrors();

        $this->assertSame(['order_new' => true, 'order_confirm' => false, 'order_ready' => false], $this->as($a, fn () => $waiter->fresh()->push_preferences));
    }

    public function test_a_new_online_order_reaches_the_phones_that_want_it_and_a_dead_token_is_removed(): void
    {
        $this->configureFcm();
        $this->fakeFcm(deadTokens: [$this->token('old')]);
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');
        $kitchen = $this->staff($a, 'kitchen', 'k@cafe-a.test');
        $this->register($owner, $this->token('owner'));
        $this->register($owner, $this->token('old'));
        $this->register($cashier, $this->token('cashier'));
        $this->register($waiter, $this->token('waiter'), 'waiter');
        $this->register($kitchen, $this->token('kitchen'));
        $this->actingAs($cashier)->post($this->dashUrl('/notifications/push'), ['events' => []]); // the cashier switched them off

        $order = $this->onlineOrder($a);

        $sent = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->map(fn ($pair) => $pair[0])->values();
        $this->assertEqualsCanonicalizing([$this->token('owner'), $this->token('old')], $sent->map(fn (Request $r) => $r['message']['token'])->all());
        $first = $sent->first();
        $this->assertSame('Bearer ya29.test-access', $first->header('Authorization')[0]);
        $this->assertSame('طلب أونلاين جديد #'.$order->number, $first['message']['notification']['title']);
        $this->assertSame((string) $order->id, $first['message']['data']['order']);
        $this->assertSame('order_new', $first['message']['data']['type']);
        $this->assertSame('http://app.egmenu.test/orders', $first['message']['data']['url']);
        $this->assertSame('high', $first['message']['android']['priority']);
        // FCM said the old token is dead: it is gone, the live one stays
        $this->assertSame([$this->token('owner')], $this->as($a, fn () => DeviceToken::query()->where('user_id', $owner->id)->pluck('token')->all()));

        // the access token is cached: a second order signs no new JWT
        $this->onlineOrder($a, 'callcenter');
        Http::assertSentCount(4); // one token exchange, two sends, then one more send
    }

    public function test_the_access_token_comes_from_a_jwt_signed_with_the_service_account_key(): void
    {
        $this->configureFcm();
        $this->fakeFcm();
        $a = $this->restaurant('cafe-a');
        $this->register($this->owner($a), $this->token('owner'));
        $this->freezeTime();

        $this->onlineOrder($a);

        $exchange = Http::recorded(fn (Request $r) => $r->url() === self::TOKEN_URL)->sole()[0];
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $exchange['grant_type']);
        [$header, $claims, $signature] = explode('.', $exchange['assertion']);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        $this->assertSame([
            'iss' => 'push@eghub-test.iam.gserviceaccount.com', 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::TOKEN_URL, 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 3600,
        ], json_decode($decode($claims), true));
        $public = openssl_pkey_get_details($this->key)['key'];
        $this->assertSame(1, openssl_verify("$header.$claims", $decode($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function test_a_ready_dine_in_order_rings_the_waiters_phones(): void
    {
        $this->configureFcm();
        $this->fakeFcm();
        $a = $this->restaurant('cafe-a');
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');
        $this->register($waiter, $this->token('waiter'), 'waiter');
        $order = $this->onlineOrder($a, 'qr', 'dine_in');
        Http::assertNothingSent(); // a table order from the QR menu: no "new order" push, and the waiter has none

        $this->as($a, function () use ($order) {
            app(OrderService::class)->transition($order, 'preparing');
            app(OrderService::class)->transition($order->fresh(), 'ready');
        });

        $push = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->sole()[0];
        $this->assertSame($this->token('waiter'), $push['message']['token']);
        $this->assertSame('طلب #'.$order->number.' جاهز', $push['message']['notification']['title']);
        $this->assertSame('ترابيزة 5', $push['message']['notification']['body']);
    }

    public function test_without_fcm_configured_nothing_is_queued_and_it_is_noted_once(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
        Queue::fake([SendPushNotification::class]);
        Http::preventStrayRequests();
        $a = $this->restaurant('cafe-a');
        $this->register($this->owner($a), $this->token('owner'))->assertOk()->assertJsonPath('configured', false);

        $this->onlineOrder($a);

        Queue::assertNothingPushed();
        $this->assertTrue(Cache::has('fcm.unconfigured.logged'));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/notifications/push'))->assertOk()->assertSee('data-push-off', false);
    }
}
