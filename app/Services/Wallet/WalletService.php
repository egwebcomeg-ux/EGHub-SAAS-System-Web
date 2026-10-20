<?php

namespace App\Services\Wallet;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Entitlements;
use App\Services\Orders\OrderException;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid wallet & gift cards (feature `wallet`). The balance is customers.credit_balance and only ever changes
 * here, through move(): inside a DB transaction, with the customer row locked, rounded to 2 decimals, never below
 * zero, and with a ledger row (wallet_transactions) carrying the delta and the balance after it.
 *
 * Money in: a top-up or a gift card sold at the POS (a cash movement of its own kind in the cashier's shift).
 * Money out: an order paid from the balance (POS, or the guest online). A cancelled order gives it back, once.
 */
final class WalletService
{
    /** How a guest can pay for a top-up or a gift card at the counter. */
    public const TOPUP_METHODS = ['cash', 'card', 'instapay', 'wallet'];

    /** The payment method of an order paid from the wallet ("wallet" is the mobile-wallet method). */
    public const METHOD = 'credit';

    public const MAX_AMOUNT = 50000;

    public static function enabled(): bool
    {
        return Entitlements::current()->allows('wallet');
    }

    /** Guest pays at the counter and the amount is added to their balance. */
    public function topUp(Customer $customer, float $amount, string $method, User $by, ?Shift $shift, string $note = ''): WalletTransaction
    {
        $amount = $this->amount($amount);
        $this->counter($method, $shift);

        return DB::transaction(function () use ($customer, $amount, $method, $by, $shift, $note) {
            $tx = $this->move($customer, $amount, 'topup', [
                'user_id' => $by->id, 'shift_id' => $shift?->id, 'method' => $method, 'note' => mb_substr($note, 0, 200),
            ]);
            $shift?->movements()->create([
                'type' => 'topup', 'method' => $method, 'amount' => $amount, 'user_id' => $by->id,
                'reason' => mb_substr(__('Wallet top-up').' · '.$customer->phone, 0, 190),
            ]);

            return $tx;
        });
    }

    /** Pays the whole order from its customer's balance (no partial payment in this version). Call inside a transaction. */
    public function payOrder(Order $order, ?User $by = null, ?Shift $shift = null): WalletTransaction
    {
        if (! self::enabled()) {
            throw new OrderException(__('Paying from the wallet is not available.'));
        }
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        if (! $customer) {
            throw new OrderException(__('Add the guest\'s mobile number to pay from the wallet.'));
        }

        return DB::transaction(fn () => $this->move($customer, -round((float) $order->total, 2), 'payment', [
            'order_id' => $order->id, 'user_id' => $by?->id, 'shift_id' => $shift?->id, 'note' => '#'.$order->number,
        ]));
    }

    /** A cancelled order paid from the wallet: what it took goes back, once (safe to call twice). */
    public function refundOrder(Order $order, ?User $by = null): ?WalletTransaction
    {
        return DB::transaction(function () use ($order, $by) {
            $rows = WalletTransaction::query()->where('order_id', $order->id)->whereIn('kind', ['payment', 'refund'])->lockForUpdate()->get();
            $owed = round(-$rows->sum('delta'), 2); // payments are negative, refunds positive
            $payment = $rows->firstWhere('kind', 'payment');
            if ($owed <= 0 || ! $payment) {
                return null;
            }
            $customer = Customer::query()->find($payment->customer_id);

            return $customer ? $this->move($customer, $owed, 'refund', [
                'order_id' => $order->id, 'user_id' => $by?->id, 'note' => '#'.$order->number,
            ]) : null;
        });
    }

    /**
     * Part of a paid order goes back to the guest's balance (a partial refund taken as balance). Null without a
     * customer on the order.
     */
    public function refundAmount(Order $order, float $amount, ?User $by = null): ?WalletTransaction
    {
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        if (! $customer || $amount <= 0) {
            return null;
        }

        return DB::transaction(fn () => $this->move($customer, round($amount, 2), 'refund', [
            'order_id' => $order->id, 'user_id' => $by?->id, 'note' => '#'.$order->number,
        ]));
    }

    /**
     * Cashback (feature `cashback`): a share of a counted order goes back to the guest's balance. Called once per
     * order from LoyaltyService::orderCompleted (which guards against counting twice). Null when nothing is due.
     */
    public function cashback(Order $order): ?WalletTransaction
    {
        $percent = (float) Settings::get('cashback_percent');
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        if (! $customer || $percent <= 0 || ! self::enabled() || ! Entitlements::current()->allows('cashback')
            || $order->total < (float) Settings::get('cashback_min_order')) {
            return null;
        }
        $amount = round($order->total * min(100, $percent) / 100, 2);
        if ($amount < 0.01) {
            return null;
        }
        $days = (int) Settings::get('cashback_expire_days');

        return DB::transaction(fn () => $this->move($customer, $amount, 'cashback', [
            'order_id' => $order->id, 'note' => '#'.$order->number, 'expires_at' => $days > 0 ? now()->addDays($days)->endOfDay() : null,
        ]));
    }

    /**
     * Daily: cashback lines past their date that were not settled are taken back — as much as is still on the
     * balance (what the guest already spent is simply spent). Returns how many lines were closed.
     */
    public function expireCashback(): int
    {
        $n = 0;
        WalletTransaction::query()->where('kind', 'cashback')->whereNull('settled_at')->where('expires_at', '<', now())
            ->orderBy('id')->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$n) {
                        $line = WalletTransaction::query()->lockForUpdate()->find($row->id);
                        if (! $line || $line->settled_at) {
                            return;
                        }
                        $customer = Customer::query()->lockForUpdate()->find($line->customer_id);
                        $take = $customer ? round(min((float) $line->delta, (float) $customer->credit_balance), 2) : 0.0;
                        if ($take > 0) {
                            $this->move($customer, -$take, 'cashback_exp', ['order_id' => $line->order_id, 'note' => $line->note]);
                        }
                        $line->forceFill(['settled_at' => now()])->save();
                        $n++;
                    });
                }
            });

        return $n;
    }

    /** A cancelled order takes its cashback back — as much of it as is still on the balance (never below zero). */
    public function reverseCashback(Order $order): ?WalletTransaction
    {
        return DB::transaction(function () use ($order) {
            $rows = WalletTransaction::query()->where('order_id', $order->id)->whereIn('kind', ['cashback', 'cashback_rev'])->lockForUpdate()->get();
            $given = round($rows->sum('delta'), 2);
            $cashback = $rows->firstWhere('kind', 'cashback');
            if ($given <= 0 || ! $cashback) {
                return null;
            }
            $customer = Customer::query()->lockForUpdate()->find($cashback->customer_id);
            $take = $customer ? round(min($given, (float) $customer->credit_balance), 2) : 0.0;
            $cashback->forceFill(['settled_at' => now()])->save(); // the expiry job leaves it alone from now on

            return $take > 0 ? $this->move($customer, -$take, 'cashback_rev', ['order_id' => $order->id, 'note' => '#'.$order->number]) : null;
        });
    }

    /** Staff corrects a balance by hand (with a reason). */
    public function adjust(Customer $customer, float $delta, User $by, string $note): WalletTransaction
    {
        $delta = round($delta, 2);
        if ($delta === 0.0 || abs($delta) > self::MAX_AMOUNT) {
            throw new OrderException(__('Enter an amount.'));
        }

        return DB::transaction(fn () => $this->move($customer, $delta, 'adjustment', ['user_id' => $by->id, 'note' => mb_substr($note, 0, 200)]));
    }

    /** A gift card sold at the counter: paid now, added to a wallet later from /gift/{code}. */
    public function sellGiftCard(float $amount, string $method, User $by, ?Shift $shift, ?string $recipientPhone = null, ?string $message = null, ?Customer $buyer = null): GiftCard
    {
        $amount = $this->amount($amount);
        $this->counter($method, $shift);
        $phone = $recipientPhone !== null && trim($recipientPhone) !== '' ? normalize_phone($recipientPhone) : null;
        if ($recipientPhone !== null && trim($recipientPhone) !== '' && ! $phone) {
            throw new OrderException(__('Enter a valid Egyptian mobile number.'));
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($amount, $method, $by, $shift, $phone, $message, $buyer) {
                    $card = GiftCard::query()->create([
                        'code' => GiftCard::newCode(), 'amount' => $amount, 'balance' => $amount, 'status' => 'active',
                        'buyer_customer_id' => $buyer?->id, 'recipient_phone' => $phone,
                        'message' => $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 200) : null,
                        'expires_at' => now()->addMonths(GiftCard::VALID_MONTHS)->endOfDay(),
                        'payment_method' => $method, 'shift_id' => $shift?->id, 'sold_by' => $by->id,
                    ]);
                    $shift?->movements()->create([
                        'type' => 'gift', 'method' => $method, 'amount' => $amount, 'user_id' => $by->id,
                        'reason' => __('Gift card').' '.$card->formattedCode(),
                    ]);

                    return $card;
                });
            } catch (QueryException $e) {
                if ($attempt >= 3) { // the same random code twice: practically never, try another
                    throw $e;
                }
            }
        }
    }

    /**
     * The recipient adds a gift card to the wallet of $phone. A card is redeemed once; codes are looked up in the
     * current restaurant only.
     *
     * @return array{card: GiftCard, customer: Customer, created: bool}
     */
    public function redeemGiftCard(string $code, string $phone, string $name = ''): array
    {
        if (! self::enabled()) {
            throw new OrderException(__('Gift cards are not available.'));
        }
        $phone = normalize_phone($phone) ?? throw new OrderException(__('Enter a valid Egyptian mobile number.'));

        return DB::transaction(function () use ($code, $phone, $name) {
            $card = GiftCard::query()->where('code', GiftCard::normalizeCode($code))->lockForUpdate()->first()
                ?? throw new OrderException(__('This gift card code is not valid.'));
            if ($card->status === 'active' && $card->isExpired()) {
                $card->update(['status' => 'expired']);
            }
            if ($card->status !== 'active') {
                throw new OrderException(match ($card->status) {
                    'redeemed' => __('This gift card was already added to a wallet.'),
                    'expired' => __('This gift card has expired.'),
                    default => __('This gift card is no longer valid.'),
                });
            }

            $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr(trim($name), 0, 100), 'source' => 'menu']);
            $created = $customer->wasRecentlyCreated;
            $this->move($customer, $card->balance, 'gift_redeem', ['gift_card_id' => $card->id, 'note' => $card->formattedCode()]);
            $card->update(['status' => 'redeemed', 'balance' => 0, 'redeemed_by_customer_id' => $customer->id, 'redeemed_at' => now()]);

            return ['card' => $card, 'customer' => $customer->refresh(), 'created' => $created];
        });
    }

    public function voidGiftCard(GiftCard $card): void
    {
        DB::transaction(function () use ($card) {
            $locked = GiftCard::query()->lockForUpdate()->findOrFail($card->id);
            if ($locked->status !== 'active') {
                throw new OrderException(__('Only an active gift card can be voided.'));
            }
            $locked->update(['status' => 'void', 'balance' => 0]);
        });
    }

    /** Applies $delta to the locked customer row and writes the ledger line. Never goes below zero. */
    private function move(Customer $customer, float $delta, string $kind, array $attributes): WalletTransaction
    {
        $delta = round($delta, 2);
        $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
        $balance = round((float) $locked->credit_balance + $delta, 2);
        if ($balance < 0) {
            throw new OrderException(__('Not enough balance: the wallet has :balance.', ['balance' => money((float) $locked->credit_balance)]));
        }
        $locked->forceFill(['credit_balance' => $balance])->save();
        $customer->setAttribute('credit_balance', $balance);

        return WalletTransaction::query()->create($attributes + [
            'customer_id' => $locked->id, 'delta' => $delta, 'balance_after' => $balance, 'kind' => $kind,
        ]);
    }

    private function amount(float $amount): float
    {
        $amount = round($amount, 2);
        if (! self::enabled()) {
            throw new OrderException(__('The wallet is not available.'));
        }
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new OrderException(__('Enter an amount between 1 and :max.', ['max' => money(self::MAX_AMOUNT)]));
        }

        return $amount;
    }

    /** Money taken at the counter goes through the cashier's open shift, like a sale. */
    private function counter(string $method, ?Shift $shift): void
    {
        if (! in_array($method, self::TOPUP_METHODS, true)) {
            throw new OrderException(__('Choose a payment method.'));
        }
        if ($shift && ! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }
        if (! $shift && Entitlements::current()->allows('shifts')) {
            throw new OrderException(__('Open a shift first to take payments.'));
        }
    }
}
