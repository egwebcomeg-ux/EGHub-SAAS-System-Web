<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Tenant;
use App\Models\TvScreen;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** The hall (order / list for the waiter / menu only), the "how will you order?" screen and the intro splash. */
class DineInModesTest extends TenantTestCase
{
    /** @var array<string, string|int> */
    private const FORM = [
        'brand_name' => 'Cafe', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522', 'color_secondary' => '#0A2540',
        'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'ordering_enabled' => 1, 'dine_in_sent' => 1,
    ];

    private function scanTable(Tenant $tenant): DiningTable
    {
        $table = $this->as($tenant, fn () => DiningTable::query()->firstOrFail());
        $this->get($this->subUrl($tenant->id, "t/{$table->qr_token}"))->assertRedirect();

        return $table;
    }

    /** @return array<string, mixed> */
    private function menuData(TestResponse $response): array
    {
        preg_match('#<script type="application/json" id="menu-data">(.*?)</script>#s', $response->getContent(), $m);

        return json_decode($m[1], true);
    }

    public function test_settings_save_the_hall_and_sending_needs_table_ordering(): void
    {
        $free = $this->restaurant('cafe-free', 'free');
        $this->actingAs($this->owner($free))->post($this->dashUrl('/settings'), self::FORM + ['dine_in_mode' => 'order'])
            ->assertSessionHasErrors('dine_in_mode');
        $this->actingAs($this->owner($free))->get($this->dashUrl('/settings'))->assertOk()->assertSee('data-dine-order-locked', false);

        $this->actingAs($this->owner($free))->post($this->dashUrl('/settings'), self::FORM + [
            'dine_in_mode' => 'list', 'dine_in_show_prices' => 1, 'dine_in_notice' => 'Give this to Ahmed', 'menu_intro' => 'full',
        ])->assertSessionHasNoErrors();
        $saved = $this->as($free, fn () => Settings::all());
        $this->assertSame('', $saved['dine_in_mode']);          // the free plan's default, kept empty
        $this->assertSame('1', $saved['dine_in_show_prices']);  // differs from the list default (hidden)
        $this->assertSame('Give this to Ahmed', $saved['dine_in_notice']);
        $this->assertSame('0', $saved['order_type_prompt']);
        $this->assertSame('full', $saved['menu_intro']);

        $pro = $this->restaurant('cafe-pro');
        $this->actingAs($this->owner($pro))->post($this->dashUrl('/settings'), self::FORM + ['dine_in_mode' => 'list', 'order_type_prompt' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('list', $this->as($pro, fn () => Settings::get('dine_in_mode')));
        $this->actingAs($this->owner($pro))->post($this->dashUrl('/settings'), self::FORM + ['dine_in_mode' => 'order', 'dine_in_show_prices' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('', $this->as($pro, fn () => Settings::get('dine_in_mode')));
        $this->assertSame('', $this->as($pro, fn () => Settings::get('dine_in_show_prices')));

        // a form without the hall fields (older clients, other tests) leaves them as they are
        $this->as($pro, fn () => Settings::put(['dine_in_mode' => 'off']));
        $this->actingAs($this->owner($pro))->post($this->dashUrl('/settings'), array_diff_key(self::FORM, ['dine_in_sent' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('off', $this->as($pro, fn () => Settings::get('dine_in_mode')));
    }

    public function test_the_free_plan_gets_a_list_for_the_waiter(): void
    {
        $a = $this->restaurant('cafe-a', 'free');
        $this->item($a, 'Latte', 65);

        $page = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-show-waiter', false)->assertDontSee('data-send-order', false)->assertDontSee('data-order-choice', false)
            ->assertSee('القائمة دي عشان تعرضها على الويتر، سلّمها لفريق الخدمة');
        $data = $this->menuData($page);
        $this->assertSame('list', $data['mode']);
        $this->assertFalse($data['hall']['prices']);
        $this->assertSame(['dine'], $data['ways']);
    }

    public function test_a_list_hides_prices_in_the_cart_and_nothing_is_sent_from_the_table(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 65);
        $this->as($a, fn () => Settings::put(['dine_in_mode' => 'list']));
        $this->scanTable($a);

        $page = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-show-waiter', false)->assertSee('data-waiter-view', false)
            ->assertDontSee('data-send-order', false)->assertDontSee('data-open-split', false)
            ->assertSee('data-call-open', false)->assertSee('data-hall-call', false)   // waiter calls still work at the table
            ->assertSee('65');                                                          // the menu itself keeps its prices
        $this->assertFalse($this->menuData($page)['hall']['prices']);
        $this->assertSame('list', $this->menuData($page)['mode']);

        $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])
            ->assertStatus(422);

        // the owner can show prices (and the bill split) on the list
        $this->as($a, fn () => Settings::put(['dine_in_show_prices' => '1']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-open-split', false)->assertSee('data-split-sheet', false);

        // menu only: no cart at all in the hall
        $this->as($a, fn () => Settings::put(['dine_in_mode' => 'off']));
        $off = $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-show-waiter', false)->assertDontSee('data-send-order', false);
        $this->assertSame('none', $this->menuData($off)['mode']);
    }

    public function test_the_table_qr_forces_the_hall_and_never_shows_the_choice(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);

        // general link: hall + takeaway (delivery is off by default) → the choice screen with only those two
        $general = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-order-choice', false)
            ->assertSee('data-way="dine"', false)->assertSee('data-way="takeaway"', false)->assertDontSee('data-way="delivery"', false)
            ->assertSee('هتطلب إزاي؟');
        $data = $this->menuData($general);
        $this->assertSame(['dine', 'takeaway'], $data['ways']);
        $this->assertSame('list', $data['hall']['mode']);   // sending from the hall needs the table QR
        $this->assertTrue($data['hall']['scanHint']);

        $this->scanTable($a);
        $table = $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-order-choice', false)
            ->assertDontSee('data-way="takeaway"', false)->assertSee('data-send-order', false);
        $this->assertSame('table', $this->menuData($table)['mode']);
        $this->assertNull($this->menuData($table)['online']);
    }

    public function test_the_choice_screen_can_be_switched_off_and_needs_two_ways(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['order_type_prompt' => '0']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-order-choice', false)->assertSee('data-way-switch', false);

        // menu only in the hall: takeaway is the only way, so nothing to choose
        $this->as($a, fn () => Settings::put(['order_type_prompt' => '1', 'dine_in_mode' => 'off']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-order-choice', false)->assertDontSee('data-way-switch', false);
    }

    public function test_the_intro_follows_the_setting_and_never_plays_on_screens_or_for_bots(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        app(Billing::class)->setAddon($a, 'kiosk_pack', 1);
        $this->as($a, fn () => Settings::put(['tagline' => 'Coffee with a view']));

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-intro', false)->assertSee('m-intro--logo', false)
            ->assertSee('Coffee with a view')->assertSee('egmenu.intro.cafe-a', false)->assertSee('اضغط للتخطي');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-intro', false);

        $this->as($a, fn () => Settings::put(['menu_intro' => 'full']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('m-intro--full', false)->assertSee('m-intro-pattern', false);
        $this->as($a, fn () => Settings::put(['menu_intro' => 'off']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-intro', false);

        $this->as($a, fn () => Settings::put(['menu_intro' => 'logo']));
        [$kiosk, $screen] = $this->as($a, fn () => [
            Kiosk::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'Counter', 'token' => random_token(10)]),
            TvScreen::query()->create(['name' => 'TV', 'token' => random_token(10)]),
        ]);
        $this->get($this->subUrl('cafe-a', "kiosk/{$kiosk->token}"))->assertOk()->assertDontSee('data-intro', false);
        $this->get($this->subUrl('cafe-a', "tv/{$screen->token}"))->assertOk()->assertDontSee('data-intro', false);
    }
}
