<?php

namespace App\Services\Orders;

use App\Models\MenuItem;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;

/**
 * The cart goal of the public menu ("add X more and get Y free", feature offers): once the food of a guest order
 * reaches the threshold, a chosen item joins the order at 0 or a percent / amount comes off. The menu only shows the
 * progress; the reward is decided here when the order is placed, with the server's own prices. It does not stack
 * with a coupon unless the owner allows it.
 */
final class CartGoal
{
    public const FEATURE = 'offers';

    public const REWARDS = ['item', 'percent', 'amount'];

    /** Guest sources that get the reward: table QR and online (pickup / delivery) orders. */
    public const SOURCES = ['qr', 'online'];

    /**
     * @param  array{amount:float, reward:string, value:float, item:?MenuItem, label:string, withCoupon:bool}  $goal
     */
    private function __construct(private array $goal) {}

    /** The goal set for the current restaurant, or null when it is off, incomplete or not in the plan. */
    public static function current(?array $settings = null): ?self
    {
        $s = $settings ?? Settings::all();
        if (($s['cart_goal_enabled'] ?? '0') !== '1' || ! Entitlements::current()->allows(self::FEATURE)) {
            return null;
        }
        $amount = round((float) ($s['cart_goal_amount'] ?? 0), 2);
        $reward = (string) ($s['cart_goal_reward'] ?? 'item');
        $value = round((float) ($s['cart_goal_value'] ?? 0), 2);
        if ($amount <= 0 || ! in_array($reward, self::REWARDS, true)) {
            return null;
        }
        $item = null;
        if ($reward === 'item') {
            $item = MenuItem::query()->where('is_active', true)->find((int) ($s['cart_goal_item'] ?? 0));
            if (! $item) {
                return null;
            }
        } elseif ($value <= 0 || ($reward === 'percent' && $value > 100)) {
            return null;
        }

        return new self([
            'amount' => $amount, 'reward' => $reward, 'value' => $value, 'item' => $item,
            'label' => trim((string) ($s['cart_goal_label'] ?? '')), 'withCoupon' => ($s['cart_goal_with_coupon'] ?? '0') === '1',
        ]);
    }

    public function amount(): float
    {
        return $this->goal['amount'];
    }

    public function reward(): string
    {
        return $this->goal['reward'];
    }

    public function item(): ?MenuItem
    {
        return $this->goal['item'];
    }

    /** What the guest gets: the item name, "10%" or "50 EGP". */
    public function rewardText(string $currency = ''): string
    {
        return match ($this->goal['reward']) {
            'item' => $this->goal['item']->displayName(),
            'percent' => rtrim(rtrim(number_format($this->goal['value'], 2, '.', ''), '0'), '.').'%',
            default => trim(money($this->goal['value']).' '.$currency),
        };
    }

    /** The owner's text, or the generated one. */
    public function label(string $currency = ''): string
    {
        return $this->goal['label'] !== '' ? $this->goal['label'] : self::autoLabel($this->goal['reward'], money($this->goal['amount']).' '.$currency, $this->rewardText($currency));
    }

    public static function autoLabel(string $reward, string $amount, string $rewardText): string
    {
        return $reward === 'item'
            ? __('Order for :amount and get :reward free', ['amount' => $amount, 'reward' => $rewardText])
            : __('Order for :amount and get :reward off', ['amount' => $amount, 'reward' => $rewardText]);
    }

    /**
     * The goal for the menu page of this branch, or null when its free item is not offered there.
     *
     * @return array{amount:float, reward:string, value:float, item:?int, rewardName:string, label:string, withCoupon:bool}|null
     */
    public function forMenu(MenuCatalog $catalog, string $currency): ?array
    {
        $item = $this->goal['item'];
        if ($item && (! $catalog->isAvailable($item) || $catalog->apply($item)->is_sold_out)) {
            return null;
        }

        return [
            'amount' => $this->goal['amount'], 'reward' => $this->goal['reward'], 'value' => $this->goal['value'],
            'item' => $item?->id, 'rewardName' => $this->rewardText($currency), 'label' => $this->label($currency),
            'withCoupon' => $this->goal['withCoupon'],
        ];
    }

    /**
     * The reward an order earns, priced by the server. $food is the order's food after staff discounts, before the
     * coupon; $catalog is the branch's menu (the free item must be offered there).
     *
     * @return array{discount:float, line:?array<string,mixed>, label:string}|null
     */
    public function rewardFor(float $food, bool $hasCoupon, MenuCatalog $catalog): ?array
    {
        if ($food + 0.001 < $this->goal['amount'] || ($hasCoupon && ! $this->goal['withCoupon'])) {
            return null;
        }
        $label = mb_substr($this->label(), 0, 120);
        if ($this->goal['reward'] === 'item') {
            $item = MenuItem::query()->with('category')->find($this->goal['item']->id);
            if (! $item || ! $catalog->isAvailable($item) || $catalog->apply($item)->is_sold_out) {
                return null;
            }

            return ['discount' => 0.0, 'label' => $label, 'line' => [
                'menu_item_id' => $item->id, 'kitchen_station_id' => $item->category?->kitchen_station_id,
                'name' => $item->displayName(), 'unit_price' => 0.0, 'original_price' => round((float) $item->price, 2), 'quantity' => 1,
                'options' => [], 'notes' => mb_substr('🎁 '.__('Free with the cart goal'), 0, 200), 'line_total' => 0.0,
            ]];
        }
        $off = $this->goal['reward'] === 'percent' ? $food * $this->goal['value'] / 100 : $this->goal['value'];

        return ['discount' => round(min($food, $off), 2), 'line' => null, 'label' => $label];
    }
}
