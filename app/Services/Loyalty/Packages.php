<?php

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\PackageUse;
use App\Models\PrepaidPackage;
use App\Models\Shift;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Orders\OrderException;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid packages (feature `prepaid_packages`): "10 coffees for 150 EGP, valid 30 days". Sold at the counter like
 * a gift card (the money goes through the cashier's shift as a prepaid movement), then the cashier takes one use
 * per visit from the customer's card. Uses are only ever taken by staff — the guest's page just shows what is left.
 */
final class Packages
{
    public static function enabled(): bool
    {
        return Entitlements::current()->allows('prepaid_packages');
    }

    /** The guest pays for a package at the POS; their copy starts today and expires after the package's days. */
    public function sell(PrepaidPackage $package, Customer $customer, string $method, User $by, ?Shift $shift): CustomerPackage
    {
        if (! self::enabled()) {
            throw new OrderException(__('Prepaid packages are not available.'));
        }
        if (! $package->is_active) {
            throw new OrderException(__('This package is no longer on sale.'));
        }
        if (! in_array($method, WalletService::TOPUP_METHODS, true)) {
            throw new OrderException(__('Choose a payment method.'));
        }
        if ($shift && ! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }
        if (! $shift && Entitlements::current()->allows('shifts')) {
            throw new OrderException(__('Open a shift first to take payments.'));
        }

        return DB::transaction(function () use ($package, $customer, $method, $by, $shift) {
            $copy = CustomerPackage::query()->create([
                'customer_id' => $customer->id, 'prepaid_package_id' => $package->id, 'name' => $package->displayName(),
                'price_paid' => $package->price, 'uses_total' => $package->uses, 'uses_left' => $package->uses,
                'expires_at' => now()->addDays($package->valid_days)->endOfDay(), 'status' => 'active',
                'payment_method' => $method, 'shift_id' => $shift?->id, 'sold_by' => $by->id,
            ]);
            $shift?->movements()->create([
                'type' => 'package', 'method' => $method, 'amount' => $package->price, 'user_id' => $by->id,
                'reason' => mb_substr(__('Package').' '.$package->displayName().' · '.$customer->phone, 0, 190),
            ]);

            return $copy;
        });
    }

    /** The cashier takes one use (the guest got their coffee). Locked so two screens can't take the last one twice. */
    public function use(CustomerPackage $copy, User $by, ?int $orderId = null, ?int $branchId = null, string $note = ''): PackageUse
    {
        return DB::transaction(function () use ($copy, $by, $orderId, $branchId, $note) {
            $locked = CustomerPackage::query()->lockForUpdate()->findOrFail($copy->id);
            if ($locked->status === 'active' && $locked->expires_at->isPast()) {
                $locked->update(['status' => 'expired']);
            }
            if (! $locked->isUsable()) {
                throw new OrderException(match ($locked->status) {
                    'expired' => __('This package has expired.'),
                    'used' => __('This package is used up.'),
                    default => __('This package can no longer be used.'),
                });
            }
            $left = $locked->uses_left - 1;
            $locked->update(['uses_left' => $left, 'status' => $left === 0 ? 'used' : 'active']);
            $copy->setRawAttributes($locked->getAttributes(), true);

            return PackageUse::query()->create([
                'customer_package_id' => $locked->id, 'user_id' => $by->id, 'order_id' => $orderId, 'branch_id' => $branchId,
                'note' => mb_substr($note, 0, 100),
            ]);
        });
    }

    /** Owner / manager cancels a package (refund is handled at the counter). */
    public function void(CustomerPackage $copy): void
    {
        DB::transaction(function () use ($copy) {
            $locked = CustomerPackage::query()->lockForUpdate()->findOrFail($copy->id);
            if ($locked->status !== 'active') {
                throw new OrderException(__('Only an active package can be voided.'));
            }
            $locked->update(['status' => 'void']);
        });
    }

    /** Daily: packages past their date. */
    public function expire(): int
    {
        return CustomerPackage::query()->where('status', 'active')->where('expires_at', '<', now())->update(['status' => 'expired']);
    }

    /** @return Collection<int, CustomerPackage> the guest's packages still good to use */
    public function activeFor(Customer $customer)
    {
        return CustomerPackage::query()->where('customer_id', $customer->id)->where('status', 'active')
            ->where('uses_left', '>', 0)->where('expires_at', '>', now())->orderBy('expires_at')->get();
    }
}
