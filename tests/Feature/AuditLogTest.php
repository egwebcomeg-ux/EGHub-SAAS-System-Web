<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class AuditLogTest extends TenantTestCase
{
    /** @return string[] the recorded actions, oldest first */
    private function actions(Tenant $t): array
    {
        return $this->as($t, fn () => AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_menu_item_changes_and_price_changes_are_recorded_with_the_user(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);
        $cat = $this->item($a, 'Seed')->category_id;
        $payload = ['category_id' => $cat, 'name' => 'Mocha', 'price' => 50, 'is_active' => 1];

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), $payload)->assertSessionHasNoErrors();
        $mocha = $this->as($a, fn () => MenuItem::query()->where('name', 'Mocha')->first());
        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$mocha->id}"), ['price' => 60] + $payload)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$mocha->id}"), ['price' => 60] + $payload);
        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$mocha->id}/delete"));

        $this->assertSame(['item.created', 'item.updated', 'item.price_changed', 'item.updated', 'item.deleted'], $this->actions($a));
        $price = $this->as($a, fn () => AuditLog::query()->where('action', 'item.price_changed')->first());
        $this->assertSame($owner->id, $price->user_id);
        $this->assertEquals(['id' => $mocha->id, 'from' => 50, 'to' => 60], $price->meta);
    }

    public function test_settings_users_orders_and_stock_are_recorded(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/settings'), [
            'brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111', 'color_secondary' => '#222222',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'ordering_enabled' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($this->dashUrl('/users'), [
            'name' => 'Sara', 'email' => 'sara@a.test', 'role' => 'cashier', 'password' => 'Secret-pass-123',
        ])->assertSessionHasNoErrors();
        $sara = $this->as($a, fn () => User::query()->where('email', 'sara@a.test')->first());
        $this->actingAs($owner)->post($this->dashUrl("/users/{$sara->id}"), [
            'name' => 'Sara', 'email' => 'sara@a.test', 'role' => 'waiter', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $latte = $this->item($a, 'Latte', 65);
        $this->get($this->menuUrl("{$a->id}/t/".$this->as($a, fn () => DiningTable::query()->first())->qr_token))->assertRedirect();
        $res = $this->postJson($this->menuUrl("{$a->id}/order"), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $latte->id, 'qty' => 1]]])->assertOk();
        $orderId = $this->as($a, fn () => Order::query()->value('id'));
        $this->actingAs($owner)->postJson($this->dashUrl("/orders/$orderId/status"), ['status' => 'cancelled', 'reason' => 'mistake'])->assertOk();

        app(\App\Billing\Billing::class)->setAddon($a, 'inventory_pack', 1); // inventory is an add-on, not in the plan
        $milk = $this->as($a, fn () => Ingredient::query()->create(['name' => 'Milk', 'unit' => 'ml', 'cost_per_unit' => 0.04]));
        $this->actingAs($owner)->post($this->dashUrl('/inventory/move'), ['type' => 'purchase', 'ingredient_id' => $milk->id, 'quantity' => 100, 'total_cost' => 5])->assertSessionHasNoErrors();

        $this->assertSame(['settings.updated', 'user.created', 'user.updated', 'order.cancelled', 'stock.purchase'], $this->actions($a));
        $this->assertNotNull($res->json('number'));
    }

    public function test_page_lists_only_own_entries_and_filters_by_user(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $ownerA = $this->owner($a);
        $manager = $this->staff($a, 'manager', 'm@a.test');
        $this->as($a, function () use ($ownerA, $manager) {
            AuditLog::query()->create(['user_id' => $ownerA->id, 'action' => 'item.deleted', 'subject' => 'Owner-only-item']);
            AuditLog::query()->create(['user_id' => $manager->id, 'action' => 'item.created', 'subject' => 'Manager-item']);
        });
        $this->as($b, fn () => AuditLog::query()->create(['action' => 'item.created', 'subject' => 'Foreign-secret']));

        $this->actingAs($ownerA)->get($this->dashUrl('/audit'))->assertOk()
            ->assertSee('Owner-only-item')->assertSee('Manager-item')->assertDontSee('Foreign-secret');
        $this->actingAs($ownerA)->get($this->dashUrl("/audit?user={$manager->id}"))->assertOk()
            ->assertSee('Manager-item')->assertDontSee('Owner-only-item');
    }

    public function test_page_needs_the_plan_feature_and_the_permission(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/audit'))->assertForbidden();

        $biz = $this->restaurant('cafe-biz', 'business');
        $this->actingAs($this->staff($biz, 'cashier', 'c@biz.test'))->get($this->dashUrl('/audit'))->assertForbidden();
        $this->actingAs($this->owner($biz))->get($this->dashUrl('/audit'))->assertOk();
    }
}
