<?php

namespace App\Services\Corporate;

use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Orders\OrderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Corporate prepaid vouchers (feature `corporate_vouchers`): contracts and their vouchers, redemption on a POS
 * order, refunds, exceptions after expiry and linking a voucher to a guest's mobile.
 *
 * Redemption mirrors the wallet: it runs inside OrderService::pay()'s transaction, with the voucher rows locked and
 * a balance check in the UPDATE itself, so the same voucher can never be spent twice. The part of the order paid by
 * vouchers is orders.corporate_amount; the rest is paid another way (cash, card…), and the shift and the reports
 * count the voucher part as its own method, never as cash in the drawer.
 */
final class CorporateVouchers
{
    /** The payment method of the part of an order paid by corporate vouchers. */
    public const METHOD = 'corporate';

    public const MAX_QUANTITY = 2000;

    public const MAX_VALUE = 50000;

    /** Vouchers on one order. */
    public const MAX_PER_ORDER = 20;

    public const APPROVAL_MINUTES = 15;

    /** Wrong manager PINs from one cashier before a pause, and the pause. */
    public const PIN_ATTEMPTS = 5;

    public const PIN_DECAY_SECONDS = 900;

    public static function enabled(): bool
    {
        return Entitlements::current()->allows('corporate_vouchers');
    }

    /**
     * @param  array{name:string, contact_name?:?string, contact_phone?:?string, reference?:?string, prefix?:?string, voucher_value:float|string,
     *              quantity:int|string, expires_on:string, mode:string, branch_ids?:?array, days?:?array, hours_from?:?string, hours_to?:?string,
     *              min_order?:float|string|null, paid_amount?:float|string|null, notes?:?string}  $data
     */
    public function createContract(array $data, User $by): CorporateContract
    {
        return DB::transaction(function () use ($data, $by) {
            $attributes = $this->attributes($data);
            $contract = CorporateContract::query()->create($attributes + [
                'prefix' => $this->prefixFor((string) ($data['prefix'] ?? ''), $attributes['name']),
                'quantity' => (int) $data['quantity'], 'voucher_value' => round((float) $data['voucher_value'], 2),
                'status' => 'active', 'created_by' => $by->id,
            ]);
            $contract->update(['paid_status' => CorporateContract::paidStatusFor($contract->paid_amount, $contract->totalValue())]);
            $this->issue($contract, $contract->quantity);
            Audit::record('corporate.created', $contract->name, ['quantity' => $contract->quantity, 'value' => $contract->voucher_value]);

            return $contract;
        });
    }

    /** Edits the contract's terms. The quantity only grows (new vouchers are added); value and mode stay as printed. */
    public function updateContract(CorporateContract $contract, array $data): CorporateContract
    {
        return DB::transaction(function () use ($contract, $data) {
            $locked = CorporateContract::query()->lockForUpdate()->findOrFail($contract->id);
            $quantity = (int) ($data['quantity'] ?? $locked->quantity);
            if ($quantity < $locked->quantity) {
                throw new OrderException(__('The quantity can only grow: void the vouchers you no longer need.'));
            }
            $attributes = $this->attributes($data);
            // the date is changed with "extend" (it moves the vouchers too); the mode is printed on the vouchers
            unset($attributes['expires_on'], $attributes['mode']);
            $locked->update($attributes + ['quantity' => $quantity]);
            $locked->update(['paid_status' => CorporateContract::paidStatusFor($locked->paid_amount, $locked->totalValue())]);
            if ($quantity > $contract->quantity) {
                $this->issue($locked, $quantity - $contract->quantity);
            }
            Audit::record('corporate.updated', $locked->name, ['quantity' => $quantity]);
            $contract->setRawAttributes($locked->getAttributes(), true);

            return $contract;
        });
    }

    /** Adds $count vouchers to the contract, serials following the last one of the same prefix. */
    public function issue(CorporateContract $contract, int $count): void
    {
        if ($count < 1 || $count > self::MAX_QUANTITY) {
            throw new OrderException(__('Between 1 and :max vouchers per contract.', ['max' => self::MAX_QUANTITY]));
        }
        $next = $this->lastSerialNumber($contract->prefix) + 1;
        $codes = $this->uniqueCodes($count);
        $now = now();
        $rows = [];
        foreach ($codes as $i => $code) {
            $rows[] = [
                'tenant_id' => tenant()->getTenantKey(), 'corporate_contract_id' => $contract->id,
                'serial' => $contract->prefix.'-'.str_pad((string) ($next + $i), 4, '0', STR_PAD_LEFT), 'code' => $code,
                'value' => $contract->voucher_value, 'balance' => $contract->voucher_value, 'status' => 'active',
                'expires_on' => $contract->expires_on->format('Y-m-d'), 'print_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            CorporateVoucher::query()->insert($chunk);
        }
    }

    /** Pause, resume or void a contract. Void is final. */
    public function setStatus(CorporateContract $contract, string $status): void
    {
        if (! isset(CorporateContract::STATUSES[$status])) {
            throw new OrderException(__('Choose a status.'));
        }
        if ($contract->status === 'void') {
            throw new OrderException(__('This contract was voided.'));
        }
        $contract->update(['status' => $status]);
        Audit::record('corporate.status', $contract->name, ['to' => $status]);
    }

    /** A new last day for the contract and every voucher of it that ends earlier. */
    public function extendContract(CorporateContract $contract, string $date): void
    {
        $day = $this->futureDate($date);
        DB::transaction(function () use ($contract, $day) {
            $from = $contract->expires_on?->format('Y-m-d');
            $contract->update(['expires_on' => $day]);
            $contract->vouchers()->where('expires_on', '<', $day)->update(['expires_on' => $day, 'updated_at' => now()]);
            Audit::record('corporate.extended', $contract->name, ['from' => $from, 'to' => $day]);
        });
    }

    public function extendVoucher(CorporateVoucher $voucher, string $date): void
    {
        $day = $this->futureDate($date);
        $from = $voucher->expires_on?->format('Y-m-d');
        $voucher->update(['expires_on' => $day]);
        Audit::record('corporate.extended', $voucher->serial, ['from' => $from, 'to' => $day]);
    }

    public function voidVoucher(CorporateVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $locked = CorporateVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            if ($locked->status !== 'active') {
                throw new OrderException(__('Only an active voucher can be voided.'));
            }
            $locked->update(['status' => 'void']);
            $voucher->status = 'void';
            Audit::record('corporate.voided', $locked->serial);
        });
    }

    /**
     * Counts a print of these vouchers: whatever was printed before is a copy ("نسخة"). Audit-logged.
     *
     * @param  Collection<int,CorporateVoucher>  $vouchers
     * @return array<int,bool> voucher id => this print is a copy
     */
    public function markPrinted(Collection $vouchers, string $layout): array
    {
        $copies = $vouchers->mapWithKeys(fn (CorporateVoucher $v) => [$v->id => $v->print_count > 0])->all();
        CorporateVoucher::query()->whereIn('id', $vouchers->pluck('id'))->update(['print_count' => DB::raw('print_count + 1'), 'printed_at' => now()]);
        $first = $vouchers->first();
        Audit::record('corporate.printed', ($first?->contract?->name ?? '').' · '.$vouchers->pluck('serial')->first().($vouchers->count() > 1 ? ' … '.$vouchers->pluck('serial')->last() : ''), [
            'count' => $vouchers->count(), 'copies' => count(array_filter($copies)), 'layout' => $layout,
        ]);

        return $copies;
    }

    /**
     * What the cashier typed or scanned: a code (one voucher) or the guest's mobile (the vouchers linked to it, and
     * those waiting for the cashier to confirm the link). Codes are looked up in this restaurant only.
     *
     * @return array{by:string, phone:?string, vouchers:list<array<string,mixed>>}
     */
    public function lookup(string $query, Branch $branch, float $total, ?User $cashier = null): array
    {
        if ($phone = normalize_phone($query)) {
            $customer = Customer::query()->where('phone', $phone)->first();
            $vouchers = CorporateVoucher::query()->with('contract')
                ->where(fn ($q) => $q->where('pending_phone', $phone)->when($customer, fn ($q) => $q->orWhere('customer_id', $customer->id)))
                ->whereIn('status', ['active'])->where('balance', '>', 0)->orderBy('expires_on')->orderBy('id')->limit(30)->get();

            return ['by' => 'phone', 'phone' => $phone, 'vouchers' => $vouchers->map(fn ($v) => $this->present($v, $branch, $total, $customer, false, $cashier))->values()->all()];
        }
        $voucher = $this->findByCode($query);

        return ['by' => 'code', 'phone' => null, 'vouchers' => [$this->present($voucher->load('contract'), $branch, $total, null, true, $cashier)]];
    }

    /** A typed / scanned code in this restaurant; a code of another restaurant reads as not found. */
    public function findByCode(string $raw): CorporateVoucher
    {
        $code = CorporateVoucher::normalizeCode($raw);
        if (! CorporateVoucher::isValidCode($code)) {
            throw new OrderException(__('This code is not valid: check it and type it again.'));
        }

        return CorporateVoucher::query()->where('code', $code)->first() ?? throw new OrderException(__('No voucher with this code.'));
    }

    /**
     * Why this voucher can't pay this order (null = it can). Expired is checked last: it is the only refusal a
     * manager can lift (an exception), so it is only offered when nothing else is wrong.
     *
     * @return array{ok:bool, reason:?string, expired:bool}
     */
    public function evaluate(CorporateVoucher $voucher, Branch $branch, float $total, ?Customer $presentedBy): array
    {
        $contract = $voucher->contract;
        $refuse = fn (string $reason, bool $expired = false) => ['ok' => false, 'reason' => $reason, 'expired' => $expired];
        if ($voucher->status === 'void' || $contract->status === 'void') {
            return $refuse(__('This voucher was cancelled.'));
        }
        if ($voucher->status === 'used' || $voucher->balance <= 0) {
            return $refuse(__('This voucher was already used.'));
        }
        if ($contract->status === 'paused') {
            return $refuse(__('The company contract is paused.'));
        }
        if ($voucher->customer_id && ! $presentedBy) {
            return $refuse(__('This voucher is linked to a guest\'s mobile: type the guest\'s mobile number instead of the code.'));
        }
        if ($voucher->customer_id && $presentedBy->id !== $voucher->customer_id) {
            return $refuse(__('This voucher is linked to another mobile number.'));
        }
        if (! $contract->allowsBranch($branch->id)) {
            return $refuse(__('This voucher is not valid in this branch.'));
        }
        if (! $contract->allowsTime(now())) {
            return $refuse(__('This voucher works only :window.', ['window' => $contract->windowLabel()]));
        }
        if ($contract->min_order && $total + 0.004 < $contract->min_order) {
            return $refuse(__('The order must be at least :amount to use this voucher.', ['amount' => money($contract->min_order)]));
        }
        if ($voucher->isExpired()) {
            return $refuse(__('Expired on :date.', ['date' => local_time($voucher->expires_on, 'Y/m/d')]), true);
        }

        return ['ok' => true, 'reason' => null, 'expired' => false];
    }

    /** The voucher as the POS shows it. The code goes back only to whoever typed it. */
    public function present(CorporateVoucher $v, Branch $branch, float $total, ?Customer $customer, bool $byCode, ?User $cashier = null): array
    {
        $linkedToThisPhone = $customer && $v->customer_id === $customer->id;
        $check = $this->evaluate($v, $branch, $total, $linkedToThisPhone ? $customer : null);
        if (! $byCode && ! $v->customer_id) {
            // asked for by this mobile but not linked yet: the cashier confirms the link first
            $check = ['ok' => false, 'reason' => __('The guest asked to link this voucher to their mobile: confirm it to use it.'), 'expired' => false];
        }

        return [
            'id' => $v->id, 'code' => $byCode ? $v->code : null, 'serial' => $v->serial, 'company' => $v->contract->name,
            'value' => $v->value, 'balance' => $v->balance, 'mode' => $v->contract->mode, 'modeLabel' => $v->contract->modeLabel(),
            'expires' => local_time($v->expires_on, 'Y/m/d'), 'expiresSort' => $v->expires_on->format('Y-m-d'),
            'ok' => $check['ok'], 'reason' => $check['reason'], 'expired' => $check['expired'],
            'linked' => $v->customer_id ? CorporateVoucher::maskPhone($v->customer?->phone) : null,
            'pending' => ! $v->customer_id && $v->pending_phone ? CorporateVoucher::maskPhone($v->pending_phone) : null,
        ];
    }

    /**
     * Pays $order (locked by the caller, inside its transaction) with the vouchers presented, earliest expiry first,
     * until the order is covered; vouchers not needed are left untouched. Single-mode vouchers lose what the order
     * didn't use. Returns the amount covered.
     *
     * @param  list<array{code?:?string, id?:?int, approval?:?string}>  $entries  a code typed / scanned, or the id of a voucher linked to $phone
     */
    public function redeemForOrder(Order $order, array $entries, ?string $phone, User $by): float
    {
        if (! self::enabled()) {
            throw new OrderException(__('Corporate vouchers are not in your plan.'));
        }
        if (count($entries) > self::MAX_PER_ORDER) {
            throw new OrderException(__('At most :n vouchers on one order.', ['n' => self::MAX_PER_ORDER]));
        }
        $customer = $phone && ($normalized = normalize_phone($phone)) ? Customer::query()->where('phone', $normalized)->first() : null;
        $branch = Branch::query()->findOrFail($order->branch_id);

        $picked = [];
        foreach ($entries as $entry) {
            if (! empty($entry['code'])) {
                $voucher = $this->findByCode((string) $entry['code']);
                $presented = $customer && $voucher->customer_id === $customer->id ? $customer : null;
            } else {
                $voucher = $customer ? CorporateVoucher::query()->where('customer_id', $customer->id)->find((int) ($entry['id'] ?? 0)) : null;
                $voucher ?? throw new OrderException(__('No voucher with this code.'));
                $presented = $customer;
            }
            $picked[$voucher->id] = ['presented' => $presented, 'approval' => $entry['approval'] ?? null];
        }
        if ($picked === []) {
            return 0.0;
        }

        $vouchers = CorporateVoucher::query()->with('contract')->whereIn('id', array_keys($picked))->lockForUpdate()
            ->orderBy('expires_on')->orderBy('id')->get();
        $approvals = [];
        foreach ($vouchers as $v) {
            $check = $this->evaluate($v, $branch, (float) $order->total, $picked[$v->id]['presented']);
            if (! $check['ok'] && $check['expired'] && ($approval = $this->approval($picked[$v->id]['approval'], $v, $by))) {
                $approvals[$v->id] = $approval;
                $check['ok'] = true;
            }
            if (! $check['ok']) {
                throw new OrderException($v->serial.': '.$check['reason']);
            }
        }

        $due = round((float) $order->total - (float) $order->corporate_amount, 2);
        $covered = 0.0;
        foreach ($vouchers as $v) {
            if ($due <= 0.004) {
                break;
            }
            $applied = round(min($v->balance, $due), 2);
            $forfeited = $v->contract->mode === 'single' ? round($v->balance - $applied, 2) : 0.0;
            $balance = round($v->balance - $applied - $forfeited, 2);
            // the balance read under the lock must still be there: a second payment of the same voucher finds 0 rows
            $updated = CorporateVoucher::query()->whereKey($v->id)->where('balance', $v->balance)->where('status', 'active')
                ->update(['balance' => $balance, 'status' => $balance <= 0 ? 'used' : 'active', 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new OrderException(__(':serial was just used on another order. Check it again.', ['serial' => $v->serial]));
            }
            CorporateVoucherRedemption::query()->create([
                'corporate_voucher_id' => $v->id, 'corporate_contract_id' => $v->corporate_contract_id, 'order_id' => $order->id,
                'amount' => $applied, 'forfeited' => $forfeited, 'branch_id' => $branch->id, 'user_id' => $by->id,
                'approved_by' => $approvals[$v->id]['approver'] ?? null, 'exception_reason' => $approvals[$v->id]['reason'] ?? null,
            ]);
            $due = round($due - $applied, 2);
            $covered = round($covered + $applied, 2);
        }
        $tokens = array_filter(array_column($picked, 'approval'));
        DB::afterCommit(fn () => array_map(fn ($t) => Cache::forget($this->approvalKey((string) $t)), $tokens));

        return $covered;
    }

    /** A cancelled order: every voucher it used gets its value back (and is usable again), once. */
    public function refundOrder(Order $order, ?User $by = null): int
    {
        return DB::transaction(function () use ($order, $by) {
            $rows = CorporateVoucherRedemption::query()->where('order_id', $order->id)->whereNull('refunded_at')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $voucher = CorporateVoucher::query()->lockForUpdate()->find($row->corporate_voucher_id);
                if ($voucher) {
                    $balance = round(min($voucher->value, $voucher->balance + $row->amount + $row->forfeited), 2);
                    $voucher->update(['balance' => $balance, 'status' => $voucher->status === 'used' && $balance > 0 ? 'active' : $voucher->status]);
                }
                $row->update(['refunded_at' => now()]);
                Audit::record('corporate.refunded', ($voucher?->serial ?? '').' · #'.$order->number, ['amount' => $row->amount], $by?->id);
            }

            return $rows->count();
        });
    }

    /**
     * A manager lifts the "expired" refusal of one voucher for this cashier, for a short while: either the cashier
     * is a manager, or a manager types their PIN on the cashier's device (rate-limited). Audit-logged with the reason.
     *
     * @return array{token:string, approver:string}
     */
    public function approveException(CorporateVoucher $voucher, User $cashier, string $reason, ?int $approverId, ?string $pin): array
    {
        $reason = mb_substr(trim($reason), 0, 200);
        if (mb_strlen($reason) < 3) {
            throw new OrderException(__('Write a short reason for the exception.'));
        }
        if (! $voucher->isExpired() || $voucher->status !== 'active' || $voucher->contract->status !== 'active') {
            throw new OrderException(__('An exception is only for an expired voucher that is otherwise good.'));
        }
        if (! $approverId || $approverId === $cashier->id) {
            if (! $cashier->can('vouchers.exception')) {
                throw new OrderException(__('A manager must approve: choose the manager and enter their PIN.'));
            }
            $approver = $cashier;
        } else {
            $approver = $this->checkPin($cashier, $approverId, (string) $pin, $voucher);
        }

        $token = random_token(16);
        Cache::put($this->approvalKey($token), [
            'voucher' => $voucher->id, 'approver' => $approver->id, 'cashier' => $cashier->id, 'reason' => $reason,
        ], now()->addMinutes(self::APPROVAL_MINUTES));
        Audit::record('corporate.exception', $voucher->serial.' · '.$voucher->contract->name, [
            'reason' => $reason, 'approved_by' => $approver->name, 'cashier' => $cashier->name,
            'expired_on' => $voucher->expires_on->format('Y-m-d'), 'balance' => $voucher->balance,
        ], $approver->id);

        return ['token' => $token, 'approver' => $approver->name];
    }

    /** Managers of this restaurant who can approve with a PIN on another person's device. */
    public function approvers(): Collection
    {
        return User::query()->where('is_active', true)->whereNotNull('manager_pin')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can('vouchers.exception'))->values();
    }

    public function setPin(User $user, ?string $pin): void
    {
        $user->forceFill(['manager_pin' => $pin === null ? null : Hash::make($pin)])->save();
        Audit::record($pin === null ? 'corporate.pin_removed' : 'corporate.pin_set', $user->name);
    }

    /** The guest's paper voucher goes with their mobile from now on (cashier, or the guest from their own card). */
    public function link(CorporateVoucher $voucher, Customer $customer, ?User $by = null): void
    {
        DB::transaction(function () use ($voucher, $customer, $by) {
            $locked = CorporateVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            if ($locked->customer_id && $locked->customer_id !== $customer->id) {
                throw new OrderException(__('This voucher is already linked to another mobile number.'));
            }
            if ($locked->status === 'void') {
                throw new OrderException(__('This voucher was cancelled.'));
            }
            $locked->update(['customer_id' => $customer->id, 'linked_at' => $locked->linked_at ?? now(), 'pending_phone' => null, 'pending_at' => null]);
            $voucher->setRawAttributes($locked->getAttributes(), true);
            Audit::record('corporate.linked', $locked->serial, ['phone' => CorporateVoucher::maskPhone($customer->phone)], $by?->id);
        });
    }

    /** The cashier links a voucher to the mobile the guest gives (a new guest is created). */
    public function linkToPhone(CorporateVoucher $voucher, string $phone, User $by): Customer
    {
        $phone = normalize_phone($phone) ?? throw new OrderException(__('Enter a valid Egyptian mobile number.'));
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => '', 'source' => 'pos']);
        $this->link($voucher, $customer, $by);

        return $customer;
    }

    /** A guest without their card on this phone asks to link: the cashier confirms when the guest is in front of them. */
    public function requestLink(CorporateVoucher $voucher, string $phone): void
    {
        $phone = normalize_phone($phone) ?? throw new OrderException(__('Enter a valid Egyptian mobile number.'));
        if ($voucher->customer_id) {
            throw new OrderException(__('This voucher is already linked to a mobile number.'));
        }
        if ($voucher->status !== 'active') {
            throw new OrderException(__('This voucher can no longer be used.'));
        }
        $voucher->update(['pending_phone' => $phone, 'pending_at' => now()]);
    }

    /** @return Collection<int,CorporateVoucher> the vouchers of a guest for their card page */
    public function heldBy(Customer $customer): Collection
    {
        return CorporateVoucher::query()->with('contract')->where('customer_id', $customer->id)
            ->where('status', '!=', 'void')->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('expires_on')->limit(30)->get();
    }

    /**
     * Totals per contract for the list: printed / used / remaining vouchers, value consumed and left.
     *
     * @return array{printed:int, used:int, remaining:int, consumed:float, forfeited:float, remaining_value:float}
     */
    public function stats(CorporateContract $contract): array
    {
        $vouchers = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)
            ->selectRaw("SUM(CASE WHEN print_count > 0 THEN 1 ELSE 0 END) as printed, SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
                SUM(CASE WHEN status = 'active' AND balance > 0 THEN 1 ELSE 0 END) as remaining,
                SUM(CASE WHEN status = 'active' THEN balance ELSE 0 END) as remaining_value")->first();
        $redeemed = CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)->whereNull('refunded_at')
            ->selectRaw('SUM(amount) as consumed, SUM(forfeited) as forfeited')->first();

        return [
            'printed' => (int) $vouchers->printed, 'used' => (int) $vouchers->used, 'remaining' => (int) $vouchers->remaining,
            'consumed' => round((float) $redeemed->consumed, 2), 'forfeited' => round((float) $redeemed->forfeited, 2),
            'remaining_value' => $contract->status === 'void' ? 0.0 : round((float) $vouchers->remaining_value, 2),
        ];
    }

    /** @return array{voucher:int, approver:int, reason:string}|null a still-valid approval for this voucher and this cashier */
    private function approval(?string $token, CorporateVoucher $voucher, User $cashier): ?array
    {
        $data = $token ? Cache::get($this->approvalKey($token)) : null;

        return is_array($data) && (int) $data['voucher'] === $voucher->id && (int) $data['cashier'] === $cashier->id ? $data : null;
    }

    private function approvalKey(string $token): string
    {
        return 'corp-exception:'.tenant()->getTenantKey().':'.$token;
    }

    private function checkPin(User $cashier, int $approverId, string $pin, CorporateVoucher $voucher): User
    {
        $key = 'corp-pin:'.tenant()->getTenantKey().':'.$cashier->id;
        $managerKey = 'corp-pin-manager:'.tenant()->getTenantKey().':'.$approverId;
        foreach ([[$key, self::PIN_ATTEMPTS], [$managerKey, self::PIN_ATTEMPTS * 2]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                throw new OrderException(__('Too many wrong PINs. Try again in :n minutes.', ['n' => max(1, (int) ceil(RateLimiter::availableIn($k) / 60))]));
            }
        }
        $approver = User::query()->where('is_active', true)->find($approverId);
        if (! $approver || ! $approver->manager_pin || ! $approver->can('vouchers.exception') || ! Hash::check($pin, $approver->manager_pin)) {
            RateLimiter::hit($key, self::PIN_DECAY_SECONDS);
            RateLimiter::hit($managerKey, self::PIN_DECAY_SECONDS);
            Audit::record('corporate.pin_failed', $voucher->serial, ['manager' => $approver?->name, 'cashier' => $cashier->name]);
            $left = RateLimiter::remaining($key, self::PIN_ATTEMPTS);
            throw new OrderException($left > 0 ? __('Wrong PIN. :n tries left.', ['n' => $left]) : __('Too many wrong PINs. Try again in :n minutes.', ['n' => (int) ceil(self::PIN_DECAY_SECONDS / 60)]));
        }
        RateLimiter::clear($key);

        return $approver;
    }

    /** @return array<string,mixed> the editable columns, cleaned */
    private function attributes(array $data): array
    {
        $paid = round(max(0, (float) ($data['paid_amount'] ?? 0)), 2);
        $branches = array_values(array_unique(array_map('intval', array_filter((array) ($data['branch_ids'] ?? [])))));
        $days = array_values(array_unique(array_map('intval', array_filter((array) ($data['days'] ?? []), fn ($d) => $d !== null && $d !== ''))));
        $from = $data['hours_from'] ?? null;
        $to = $data['hours_to'] ?? null;
        $out = [
            'name' => mb_substr(trim((string) $data['name']), 0, 150),
            'contact_name' => ($v = trim((string) ($data['contact_name'] ?? ''))) !== '' ? mb_substr($v, 0, 100) : null,
            'contact_phone' => ($v = trim((string) ($data['contact_phone'] ?? ''))) !== '' ? mb_substr($v, 0, 20) : null,
            'reference' => ($v = trim((string) ($data['reference'] ?? ''))) !== '' ? mb_substr($v, 0, 60) : null,
            'mode' => ($data['mode'] ?? 'single') === 'balance' ? 'balance' : 'single',
            'branch_ids' => $branches ?: null,
            'days' => $days && count($days) < 7 ? $days : null,
            'hours_from' => $from && $to ? $from : null, 'hours_to' => $from && $to ? $to : null,
            'min_order' => ($m = (float) ($data['min_order'] ?? 0)) > 0 ? round($m, 2) : null,
            'paid_amount' => $paid,
            'notes' => ($v = trim((string) ($data['notes'] ?? ''))) !== '' ? mb_substr($v, 0, 2000) : null,
        ];
        if (isset($data['expires_on'])) {
            $out['expires_on'] = $data['expires_on'];
        }

        return $out;
    }

    /** EVA from "Eva Pharma", or the one chosen; an Arabic-only name gets "CV". */
    private function prefixFor(string $chosen, string $name): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $chosen));
        if ($clean === '') {
            $first = preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
            $clean = strtoupper(substr($first, 0, 4));
        }

        return substr($clean !== '' ? $clean : 'CV', 0, 6);
    }

    private function lastSerialNumber(string $prefix): int
    {
        return (int) CorporateVoucher::query()->where('serial', 'like', $prefix.'-%')->pluck('serial')
            ->map(fn ($s) => (int) substr((string) $s, strlen($prefix) + 1))->max();
    }

    /** @return list<string> $count new codes, none already used in this restaurant */
    private function uniqueCodes(int $count): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $batch = [];
            while (count($batch) + count($codes) < $count) {
                $batch[CorporateVoucher::newCode()] = true;
            }
            $batch = array_diff_key($batch, $codes);
            $taken = CorporateVoucher::query()->whereIn('code', array_keys($batch))->pluck('code')->all();
            $codes += array_diff_key($batch, array_flip($taken));
        }

        return array_keys($codes);
    }

    private function futureDate(string $date): string
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw new OrderException(__('Choose a date.'));
        }
        if ($day->lt(now()->startOfDay())) {
            throw new OrderException(__('The new date can\'t be in the past.'));
        }

        return $day->format('Y-m-d');
    }
}
