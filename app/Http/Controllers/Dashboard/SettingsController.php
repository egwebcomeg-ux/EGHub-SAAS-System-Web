<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Import\Ai\AiException;
use App\Services\Import\Ai\AiProviders;
use App\Services\Settings;
use App\Support\BusinessType;
use App\Support\Cities;
use App\Support\Cuisines;
use App\Support\MenuThemes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Brand, look, contact and ordering settings of the restaurant. */
class SettingsController extends Controller
{
    public const FONTS = ['Cairo', 'Tajawal', 'Almarai', 'IBM Plex Sans Arabic', 'Readex Pro', 'El Messiri'];

    public function edit(): View
    {
        return view('dashboard.settings', [
            's' => Settings::all(), 'fonts' => self::FONTS, 'branding' => Entitlements::current()->allows('full_branding'),
            'themes' => Entitlements::current()->allows('menu_themes'),
            'ai' => Entitlements::current()->allows('ai_import'), 'providers' => AiProviders::PROVIDERS, 'aiKey' => AiProviders::masked(),
        ]);
    }

    public function update(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => 'required|string|max:80',
            'brand_name_en' => 'nullable|string|max:80',
            'tagline' => 'nullable|string|max:150',
            'tagline_en' => 'nullable|string|max:150',
            'currency' => 'required|string|max:10',
            'currency_en' => 'required|string|max:10',
            'color_primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_secondary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_bg' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['required', Rule::in(self::FONTS)],
            'default_locale' => ['required', Rule::in(['ar', 'en'])],
            'menu_theme' => ['nullable', Rule::in(MenuThemes::keys())],
            'business_type' => ['nullable', Rule::in(array_keys(BusinessType::TYPES))],
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
            'instagram' => 'nullable|url|max:200',
            'facebook' => 'nullable|url|max:200',
            'tiktok' => 'nullable|url|max:200',
            'google_review_url' => 'nullable|url|max:300',
            'city' => ['nullable', Rule::in(array_keys(Cities::ALL))],
            'area' => 'nullable|string|max:60',
            'cuisine' => 'nullable|array|max:6',
            'cuisine.*' => [Rule::in(array_keys(Cuisines::ALL))],
            'short_description' => 'nullable|string|max:160',
            'short_description_en' => 'nullable|string|max:160',
            'service_percent' => 'nullable|numeric|min:0|max:30',
            'online_prep_minutes' => 'nullable|integer|min:5|max:240',
            'tax_percent' => 'nullable|numeric|min:0|max:30',
            'ai_provider' => ['nullable', Rule::in(array_keys(AiProviders::PROVIDERS))],
            'ai_model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'ai_key' => 'nullable|string|max:300',
            'logo' => 'nullable|file|max:5120',
            'cover' => 'nullable|file|max:5120',
        ]);

        $values = collect($data)->except(['logo', 'cover', 'ai_key', 'cuisine'])->map(fn ($v) => (string) ($v ?? ''))->all();
        $values['cuisine'] = implode(',', array_values(array_unique($data['cuisine'] ?? [])));
        $values['listed_in_directory'] = $request->boolean('listed_in_directory') ? '1' : '0';
        // the key is only replaced when a new one is typed, and is stored encrypted
        if (! empty($data['ai_key'])) {
            $values['ai_key'] = AiProviders::encrypt($data['ai_key']);
        } elseif ($request->boolean('remove_ai_key')) {
            $values['ai_key'] = '';
        }
        if (($values['business_type'] ?? '') === '') {
            unset($values['business_type']); // not sent: keep the current type
        }
        $values['english_enabled'] = $request->boolean('english_enabled') ? '1' : '0';
        $values['ordering_enabled'] = $request->boolean('ordering_enabled') ? '1' : '0';
        if (Entitlements::current()->allows('online_ordering')) {
            $values['online_takeaway'] = $request->boolean('online_takeaway') ? '1' : '0';
            $values['online_delivery'] = $request->boolean('online_delivery') ? '1' : '0';
        }
        if (($values['online_prep_minutes'] ?? '') === '') {
            unset($values['online_prep_minutes']);
        }

        if (! Entitlements::current()->allows('menu_themes')) {
            unset($values['menu_theme']);
        }
        // Colors, fonts and logo are part of the branding feature; without it the EgMenu defaults stay.
        if (! Entitlements::current()->allows('full_branding')) {
            foreach (['color_primary', 'color_secondary', 'color_bg', 'font'] as $key) {
                unset($values[$key]);
            }
        }
        foreach (['logo', 'cover'] as $key) {
            if ($request->hasFile($key)) {
                $images->delete(Settings::get($key));
                $values[$key] = $images->store($request->file($key), $key);
            } elseif ($request->boolean("remove_$key")) {
                $images->delete(Settings::get($key));
                $values[$key] = '';
            }
        }
        Settings::put($values);
        Audit::record('settings.updated', '', ['keys' => array_values(array_diff(array_keys($values), ['ai_key']))]);

        return back()->with('ok', __('Settings saved.'));
    }

    /** One tiny request with the saved key and model, so the owner knows the import will work. */
    public function testAi(): RedirectResponse
    {
        try {
            AiProviders::make()->ping();
        } catch (AiException|\RuntimeException $e) {
            return back()->withErrors(['ai_key' => $e->getMessage()]);
        }

        return back()->with('ok', __('The AI key works.'));
    }
}
