<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\TenantDiscount;
use App\Services\Crm\TenantDiscounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A discount on the restaurant's next subscription invoice(s), given from the call screen (crm.discount): never above
 * the caller's own limit (PlatformAdmin::discountLimit(), the owner up to TenantDiscount::CAP), with an expiry and a
 * note, audited (App\Services\Crm\TenantDiscounts).
 */
class TenantDiscountsController extends Controller
{
    public function store(Request $request, Lead $lead, TenantDiscounts $discounts): RedirectResponse
    {
        $tenant = $lead->tenant;
        abort_unless($tenant, 404);
        $admin = $request->user('admin');
        $limit = $admin->discountLimit();
        $data = $request->validate([
            'percent' => ['required', 'numeric', 'min:1', 'max:'.$limit],
            'invoices' => ['required', 'integer', 'min:1', 'max:'.TenantDiscount::MAX_INVOICES],
            'expires_at' => ['required', 'date', 'after:'.local_today(), 'before_or_equal:'.local_at()->addDays(180)->toDateString()],
            'note' => 'required|string|max:255',
        ], [
            'percent.max' => __('Your limit is :max%. Ask the platform owner for a bigger discount.', ['max' => $limit]),
        ]);
        $expires = local_day_end($data['expires_at']);
        $discount = $discounts->grant($tenant, $admin, round((float) $data['percent'], 2), (int) $data['invoices'], $expires, trim($data['note']));

        return redirect()->route('admin.crm.show', $lead)->with('ok', __(':pct% off the next invoice is saved until :date.', [
            'pct' => money($discount->percent), 'date' => local_time($discount->expires_at, 'Y/m/d'),
        ]));
    }

    public function revoke(Request $request, TenantDiscount $discount, TenantDiscounts $discounts): RedirectResponse
    {
        $discounts->revoke($discount, $request->user('admin'));

        return back()->with('ok', __('Discount cancelled.'));
    }
}
