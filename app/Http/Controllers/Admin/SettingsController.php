<?php

namespace App\Http\Controllers\Admin;

use App\Billing\OnlinePayments;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\PhoneVerification;
use App\Support\SocialLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * How restaurants pay EgWeb. Manual methods (InstaPay, Vodafone Cash): the restaurant transfers, the admin confirms
 * and extends the subscription. Online (Kashier, App\Billing\OnlinePayments): switched on here with the methods
 * offered; its keys stay in .env, never in the database, and are only shown here as set / missing.
 */
class SettingsController extends Controller
{
    public const KEYS = [
        'pay_instapay_enabled', 'pay_instapay_handle', 'pay_vodafone_enabled', 'pay_vodafone_number', 'pay_instructions',
        'gateway_kashier_enabled', 'kashier_methods', 'kashier_installments_min', 'support_whatsapp', 'support_email',
        'sales_whatsapp', 'contact_email', 'server_ip', 'domain_cart_url',
        // launch offer (App\Billing\LaunchOffer): the second branch free for the first year of a Complete subscription
        'launch_offer_branch_enabled', 'launch_offer_branch_ends',
        // last day of the launch price next to the crossed-out price (App\Models\Plan::showsLaunchDiscount)
        'launch_offer_ends_at',
        // company legal details shown in the site footer and the legal pages when filled
        'legal_entity', 'commercial_register', 'tax_id', 'legal_address',
        'social_facebook', 'social_instagram', 'social_tiktok', 'social_youtube', 'social_linkedin', 'social_x',
    ];

    public function edit(OnlinePayments $online): View
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
            // read-only: which Kashier keys are in .env (never their values) and the addresses to give Kashier
            'kashier' => [
                'keys' => collect(['merchant_id' => 'KASHIER_MERCHANT_ID', 'api_key' => 'KASHIER_API_KEY', 'secret_key' => 'KASHIER_SECRET_KEY'])
                    ->map(fn (string $env, string $key) => ['env' => $env, 'set' => trim((string) config("services.kashier.$key")) !== ''])->all(),
                'mode' => config('services.kashier.mode') === 'live' ? 'live' : 'test',
                'configured' => $online->gateway()->isConfigured(),
                'webhook_url' => route('dash.billing.webhook', 'kashier'),
                'return_url' => route('dash.billing.return', 'EGM-XXXXXX-XXXXX'),
                'methods' => explode(',', $values['kashier_methods']),
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
            'launch_offer_branch_ends' => 'nullable|date',
            'launch_offer_ends_at' => 'nullable|date_format:Y-m-d',
            'legal_entity' => 'nullable|string|max:160',
            'commercial_register' => 'nullable|string|max:60',
            'tax_id' => 'nullable|string|max:60',
            'legal_address' => 'nullable|string|max:250',
            'kashier_methods' => 'nullable|array',
            'kashier_methods.*' => ['string', Rule::in(array_keys(OnlinePayments::METHODS))],
            'kashier_installments_min' => 'nullable|numeric|min:0|max:1000000',
        ] + collect(SocialLinks::NETWORKS)->mapWithKeys(fn ($network, string $key) => [SocialLinks::key($key) => 'nullable|url:https|max:300'])->all());
        foreach (self::KEYS as $key) {
            $value = match (true) {
                str_ends_with($key, '_enabled') => $request->boolean($key) ? '1' : '0',
                $key === 'kashier_methods' => implode(',', array_intersect(array_keys(OnlinePayments::METHODS), (array) $request->input($key, []))),
                default => (string) $request->input($key, ''),
            };
            PlatformSetting::put($key, $value);
        }

        return back()->with('ok', __('Settings saved.'));
    }
}
