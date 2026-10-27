<?php

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Marketing\WelcomeReward;
use App\Services\Notify\GuestNotifier;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Customer stats and the digital loyalty card.
 *
 * An order "counts" once (orders.loyalty_applied_at) when it is paid or served, whichever comes first: the guest's
 * visit and spend are recorded and the card earns stamps (visits / qualifying items) or points. Stamps are never
 * earned by the guest alone — only through a counted order or a staff member. Reaching a milestone creates a
 * reward to redeem at the counter; reaching the end of the card starts a new one (extra stamps carry over).
 */
final class LoyaltyService
{
    public function program(): ?LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->where('is_active', true)->latest('id')->first();
        if (! $program) {
            return null;
        }
        $rights = Entitlements::current();
        $allowed = $program->type === 'points' ? $rights->allows('loyalty_points') : $rights->allows('loyalty_stamps');

        return $allowed ? $program : null;
    }

    /** @return list<array{at:int, type:string, value:?float, label:string}> rewards along the card, the last one at its end */
    public function milestones(LoyaltyProgram $program): array
    {
        $list = collect($program->milestones ?? [])
            ->filter(fn ($m) => (int) ($m['at'] ?? 0) > 0 && trim((string) ($m['label'] ?? '')) !== '')
            ->map(fn ($m) => ['at' => (int) $m['at'], 'type' => $m['type'] ?? 'free_item', 'value' => isset($m['value']) ? (float) $m['value'] : null, 'label' => (string) $m['label']]);
        if ($program->type !== 'points') {
            $list = $list->filter(fn ($m) => $m['at'] <= $program->stamps_required);
            if (! $list->contains(fn ($m) => $m['at'] === (int) $program->stamps_required)) {
                $list->push(['at' => (int) $program->stamps_required, 'type' => $program->reward_type, 'value' => $program->reward_value, 'label' => $program->reward_label]);
            }
        }

        return $list->sortBy('at')->unique('at')->values()->all();
    }

    /** Guest joins from the menu (phone + name) or staff adds them: customer + card in the active program. */
    public function enroll(string $phone, string $name = '', string $source = 'menu'): LoyaltyCard
    {
        $program = $this->program() ?? throw new \RuntimeException('No active loyalty program');

        return DB::transaction(function () use ($program, $phone, $name, $source) {
            $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr($name, 0, 100), 'source' => $source]);
            if ($customer->name === '' && $name !== '') {
                $customer->update(['name' => mb_substr($name, 0, 100)]);
            }

            return $this->card($program, $customer);
        });
    }

    public function card(LoyaltyProgram $program, Customer $customer): LoyaltyCard
    {
        return LoyaltyCard::query()->firstOrCreate(['loyalty_program_id' => $program->id, 'customer_id' => $customer->id]);
    }

    /** Called when an order is paid or served. Safe to call twice. */
    public function orderCompleted(Order $order): void
    {
        if ($order->loyalty_applied_at || $order->status === 'cancelled' || ! $order->customer_id) {
            return;
        }
        DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->with(['items.menuItem', 'customer'])->find($order->id);
            if ($order->loyalty_applied_at) {
                return;
            }
            $order->forceFill(['loyalty_applied_at' => now()])->save();
            $customer = $order->customer;
            $customer->forceFill([
                'visits_count' => $customer->visits_count + 1, 'total_spent' => round($customer->total_spent + $order->total, 2), 'last_visit_at' => now(),
            ])->save();
            app(WalletService::class)->cashback($order); // cashback feature: a share of the order back to the wallet

            $program = $this->program();
            if (! $program) {
                return;
            }
            $card = $this->card($program, $customer);
            $earned = $this->earned($program, $card, $order);
            if ($earned > 0) {
                $this->add($card, $earned, $program->type === 'points' ? 'points' : 'stamp', $order->id, null, $order->branch_id);
            }
            // «سجّل وخد …» as points: once, with the guest's first counted online order
            if ($program->type === 'points' && ($gift = WelcomeReward::pointsFor($order, $customer)) > 0) {
                $this->add($card, $gift, 'points', $order->id, null, $order->branch_id, WelcomeReward::SOURCE);
            }
        });
    }

    /** Cancelled after it counted: take back the visit, spend and what the card earned from it. */
    public function orderReversed(Order $order): void
    {
        if (! $order->loyalty_applied_at) {
            return;
        }
        DB::transaction(function () use ($order) {
            $order->forceFill(['loyalty_applied_at' => null])->save();
            if ($customer = $order->customer) {
                $customer->forceFill(['visits_count' => max(0, $customer->visits_count - 1), 'total_spent' => max(0, round($customer->total_spent - $order->total, 2))])->save();
            }
            app(WalletService::class)->reverseCashback($order);
            foreach (LoyaltyTransaction::query()->where('order_id', $order->id)->whereIn('type', ['stamp', 'points'])->with('card')->get() as $t) {
                $card = $t->card;
                $take = min($card->stamps, $t->amount);
                $card->forceFill(['stamps' => $card->stamps - $take, 'lifetime_stamps' => max(0, $card->lifetime_stamps - $t->amount)])->save();
                LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => 'reverse', 'amount' => -$take, 'order_id' => $order->id, 'note' => 'order cancelled']);
            }
        });
    }

    /** Staff adds stamps by hand (scanned the guest's card at the counter). */
    public function stamp(LoyaltyCard $card, int $count, User $by, ?int $branchId = null, string $note = ''): LoyaltyCard
    {
        $program = $card->program;

        return $this->add($card, max(1, min(20, $count)), $program->type === 'points' ? 'points' : 'stamp', null, $by, $branchId, $note);
    }

    public function redeem(LoyaltyReward $reward, User $by, ?int $orderId = null): LoyaltyReward
    {
        if ($reward->status !== 'available') {
            throw new \RuntimeException(__('This reward was already used.'));
        }

        return DB::transaction(function () use ($reward, $by, $orderId) {
            $reward->update(['status' => 'redeemed', 'redeemed_at' => now(), 'redeemed_by' => $by->id, 'order_id' => $orderId]);
            $card = $reward->card;
            $card->forceFill(['rewards_available' => max(0, $card->rewards_available - 1), 'rewards_redeemed' => $card->rewards_redeemed + 1])->save();
            LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => 'redeem', 'amount' => 0, 'user_id' => $by->id, 'order_id' => $orderId, 'note' => $reward->label]);

            return $reward;
        });
    }

    /** Points programs: staff exchanges the points for a reward priced in points. */
    public function claimPoints(LoyaltyCard $card, int $milestone, User $by): LoyaltyReward
    {
        $m = $this->milestones($card->program)[$milestone] ?? throw new \RuntimeException('Unknown reward');
        if ($card->stamps < $m['at']) {
            throw new \RuntimeException(__('Not enough points.'));
        }

        return DB::transaction(function () use ($card, $m, $by) {
            $card->forceFill(['stamps' => $card->stamps - $m['at']])->save();
            LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => 'reward', 'amount' => -$m['at'], 'user_id' => $by->id, 'note' => $m['label']]);
            $reward = $card->rewards()->create(['tenant_id' => $card->tenant_id, 'label' => $m['label'], 'type' => $m['type'], 'value' => $m['value']]);
            $card->increment('rewards_available');

            return $this->redeem($reward, $by);
        });
    }

    /** Daily: stamps older than the program's expiry are cleared (rewards already reached stay). */
    public function expireStamps(): int
    {
        $n = 0;
        LoyaltyProgram::query()->whereNotNull('stamps_expire_days')->where('type', '!=', 'points')->get()->each(function (LoyaltyProgram $p) use (&$n) {
            $p->cards()->where('stamps', '>', 0)->where('last_stamp_at', '<', now()->subDays($p->stamps_expire_days))->get()
                ->each(function (LoyaltyCard $card) use (&$n) {
                    LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => 'expire', 'amount' => -$card->stamps, 'note' => 'expired']);
                    $card->forceFill(['stamps' => 0])->save();
                    $n++;
                });
        });

        return $n;
    }

    private function earned(LoyaltyProgram $program, LoyaltyCard $card, Order $order): int
    {
        return match ($program->type) {
            'visits' => $order->subtotal >= $program->min_order && ! ($card->last_stamp_at?->isToday()) ? 1 : 0,
            'items' => (int) $order->items->filter(function ($line) use ($program) {
                $q = $program->qualifying_items ?? [];

                return in_array($line->menu_item_id, array_map('intval', $q['items'] ?? []), true)
                    || in_array((int) $line->menuItem?->category_id, array_map('intval', $q['categories'] ?? []), true);
            })->sum('quantity'),
            'points' => (int) floor($order->total * $program->points_per_egp),
            default => 0,
        };
    }

    /** Adds stamps/points and creates the rewards of every milestone passed; a full card starts over. */
    private function add(LoyaltyCard $card, int $amount, string $type, ?int $orderId, ?User $by, ?int $branchId, string $note = ''): LoyaltyCard
    {
        return DB::transaction(function () use ($card, $amount, $type, $orderId, $by, $branchId, $note) {
            $card = LoyaltyCard::query()->lockForUpdate()->with('program')->find($card->id);
            $program = $card->program;
            LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => $type, 'amount' => $amount, 'order_id' => $orderId,
                'user_id' => $by?->id, 'branch_id' => $branchId, 'note' => $note]);

            $before = $card->stamps;
            $after = $before + $amount;
            $card->lifetime_stamps += $amount;
            $card->last_stamp_at = now();

            if ($program->type !== 'points') {
                $size = max(1, (int) $program->stamps_required);
                $milestones = $this->milestones($program);
                // walk stamp by stamp so carried-over stamps on a new card can reach its milestones too
                for ($s = $before + 1; $s <= $after; $s++) {
                    $position = (($s - 1) % $size) + 1;
                    foreach ($milestones as $m) {
                        if ($m['at'] === $position) {
                            $card->rewards()->create(['tenant_id' => $card->tenant_id, 'label' => $m['label'], 'type' => $m['type'], 'value' => $m['value']]);
                            $card->rewards_available++;
                            $label = $m['label'];
                            DB::afterCommit(fn () => app(GuestNotifier::class)->rewardEarned($card->customer, $label)); // WhatsApp
                            LoyaltyTransaction::query()->create(['loyalty_card_id' => $card->id, 'type' => 'reward', 'amount' => 0, 'order_id' => $orderId, 'note' => $m['label']]);
                        }
                    }
                }
                $after %= $size;
            }
            $card->stamps = $after;
            $card->save();

            return $card;
        });
    }
}
