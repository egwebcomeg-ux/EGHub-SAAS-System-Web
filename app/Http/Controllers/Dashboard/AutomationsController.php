<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CampaignAutomation;
use App\Models\Coupon;
use App\Services\Audit;
use App\Services\Marketing\Automations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The "Automations" tab of the campaigns page: welcome, winback and birthday messages (plan feature campaigns). The
 * welcome one is also on the rewards page, next to the welcome gift; its forms come back there.
 */
class AutomationsController extends Controller
{
    /** Saves one automation's text, coupon and send window (and switches it on or off). */
    public function update(Request $request, string $kind, Automations $automations): RedirectResponse
    {
        $automation = $automations->all()->get($kind) ?? abort(404);
        $request->merge(['coupon_code' => Coupon::normalize((string) $request->input('coupon_code', ''))]);
        $data = $request->validate([
            'message' => 'required|string|max:1000',
            'days' => ['nullable', Rule::requiredIf($kind === 'winback'), 'integer', 'min:7', 'max:365'],
            'send_from' => 'required|integer|min:0|max:23',
            'send_to' => 'required|integer|min:1|max:24|gt:send_from',
            'coupon_id' => ['nullable', 'integer', Rule::exists('coupons', 'id')->where('tenant_id', tenant()->getTenantKey())->whereNull('customer_id')],
            'coupon_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_-]{3,30}$/',
                Rule::unique('coupons', 'code')->where('tenant_id', tenant()->getTenantKey())],
            'coupon_type' => ['nullable', 'required_with:coupon_code', Rule::in(['percent', 'amount'])],
            'coupon_value' => ['nullable', 'required_with:coupon_code', 'numeric', 'min:1', $request->input('coupon_type') === 'percent' ? 'max:100' : 'max:100000'],
        ], [], ['coupon_code' => __('Coupon code')]);

        DB::transaction(function () use ($automation, $data, $request, $kind) {
            $couponId = isset($data['coupon_id']) ? (int) $data['coupon_id'] : null;
            if (($data['coupon_code'] ?? '') !== '') {
                // a new coupon made here: reusable by every guest of the automation, once each, never expiring
                $couponId = Coupon::query()->create([
                    'code' => $data['coupon_code'], 'source' => 'automation', 'type' => $data['coupon_type'], 'value' => $data['coupon_value'],
                    'min_order' => 0, 'max_uses' => null, 'expires_at' => null, 'once_per_customer' => true,
                ])->id;
            }
            $automation->update([
                'message' => $data['message'], 'is_enabled' => $request->boolean('is_enabled'),
                'days' => $kind === 'winback' ? (int) $data['days'] : $automation->days,
                'send_from' => (int) $data['send_from'], 'send_to' => (int) $data['send_to'],
                'coupon_id' => $request->boolean('remove_coupon') ? null : $couponId,
            ]);
        });
        Audit::record('automation.updated', $kind, ['enabled' => $automation->is_enabled, 'coupon' => $automation->coupon_id]);

        return $this->backTo($request)
            ->with('ok', $automation->is_enabled ? __('Automation saved and running.') : __('Automation saved (off).'));
    }

    /** Quick on/off switch. */
    public function toggle(Request $request, string $kind, Automations $automations): RedirectResponse
    {
        $automation = $automations->all()->get($kind) ?? abort(404);
        $automation->update(['is_enabled' => ! $automation->is_enabled]);
        Audit::record('automation.toggled', $kind, ['enabled' => $automation->is_enabled]);

        return $this->backTo($request)
            ->with('ok', $automation->is_enabled ? __(':name is on.', ['name' => CampaignAutomation::kindLabel($kind)]) : __(':name is off.', ['name' => CampaignAutomation::kindLabel($kind)]));
    }

    /** Back where the form was: the welcome section of the rewards page, or the automations tab of the campaigns page. */
    private function backTo(Request $request): RedirectResponse
    {
        return $request->input('return') === 'rewards'
            ? redirect()->to(route('dash.rewards').'#welcome')
            : redirect()->route('dash.campaigns', ['tab' => 'automations']);
    }
}
