<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\PhoneVerification;
use App\Support\SocialLinks;
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
        'sales_whatsapp', 'contact_email', 'server_ip', 'domain_cart_url',
        'social_facebook', 'social_instagram', 'social_tiktok', 'social_youtube', 'social_linkedin', 'social_x',
    ];

    public function edit(): View
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = PlatformSetting::get($key, '');
        }

        return view('admin.settings', [
            's' => $values,
            // read-only: the .env side of WhatsApp phone confirmation, shown so the admin can paste it into Meta
            'whatsapp' => [
                'number' => PhoneVerification::number(),
                'source' => PhoneVerification::numberSource(),
                'webhook_url' => route('webhooks.whatsapp'),
                'verify_token' => (string) config('whatsapp.verify_token'),
                'app_secret_set' => trim((string) config('whatsapp.app_secret')) !== '',
                'provider_secret_set' => trim((string) config('whatsapp.webhook.secret')) !== '',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'pay_instapay_handle' => 'nullable|string|max:120',
            'pay_vodafone_number' => 'nullable|string|max:20',
            'pay_instructions' => 'nullable|string|max:2000',
            'support_whatsapp' => 'nullable|string|max:20',
            'support_email' => 'nullable|email|max:120',
            'sales_whatsapp' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:120',
            'server_ip' => 'nullable|ip',
            'domain_cart_url' => 'nullable|url|max:300',
        ] + collect(SocialLinks::NETWORKS)->mapWithKeys(fn ($network, string $key) => [SocialLinks::key($key) => 'nullable|url:https|max:300'])->all());
        foreach (self::KEYS as $key) {
            $value = str_ends_with($key, '_enabled') ? ($request->boolean($key) ? '1' : '0') : (string) $request->input($key, '');
            PlatformSetting::put($key, $value);
        }

        return back()->with('ok', __('Settings saved.'));
    }
}
