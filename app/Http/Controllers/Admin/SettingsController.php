<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * How restaurants pay EgWeb. Manual methods (InstaPay, Vodafone Cash) work today: the restaurant transfers,
 * the admin confirms and extends the subscription. Gateways (Paymob, Kashier, Geidea) are switched on here once
 * a contract exists; their keys stay in .env, never in the database.
 */
class SettingsController extends Controller
{
    public const KEYS = [
        'pay_instapay_enabled', 'pay_instapay_handle', 'pay_vodafone_enabled', 'pay_vodafone_number', 'pay_instructions',
        'gateway_paymob_enabled', 'gateway_kashier_enabled', 'gateway_geidea_enabled', 'support_whatsapp', 'support_email',
    ];

    public function edit(): View
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = PlatformSetting::get($key, '');
        }
        return view('admin.settings', ['s' => $values]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'pay_instapay_handle' => 'nullable|string|max:120',
            'pay_vodafone_number' => 'nullable|string|max:20',
            'pay_instructions'    => 'nullable|string|max:2000',
            'support_whatsapp'    => 'nullable|string|max:20',
            'support_email'       => 'nullable|email|max:120',
        ]);
        foreach (self::KEYS as $key) {
            $value = str_ends_with($key, '_enabled') ? ($request->boolean($key) ? '1' : '0') : (string) $request->input($key, '');
            PlatformSetting::put($key, $value);
        }
        return back()->with('ok', __('Settings saved.'));
    }
}
