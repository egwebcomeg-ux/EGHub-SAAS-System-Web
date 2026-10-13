<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Settings;
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
        return view('dashboard.settings', ['s' => Settings::all(), 'fonts' => self::FONTS, 'branding' => Entitlements::current()->allows('full_branding')]);
    }

    public function update(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'brand_name'        => 'required|string|max:80',
            'brand_name_en'     => 'nullable|string|max:80',
            'tagline'           => 'nullable|string|max:150',
            'tagline_en'        => 'nullable|string|max:150',
            'currency'          => 'required|string|max:10',
            'currency_en'       => 'required|string|max:10',
            'color_primary'     => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_secondary'   => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_bg'          => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font'              => ['required', Rule::in(self::FONTS)],
            'default_locale'    => ['required', Rule::in(['ar', 'en'])],
            'phone'             => 'nullable|string|max:20',
            'whatsapp'          => 'nullable|string|max:20',
            'instagram'         => 'nullable|url|max:200',
            'facebook'          => 'nullable|url|max:200',
            'tiktok'            => 'nullable|url|max:200',
            'google_review_url' => 'nullable|url|max:300',
            'service_percent'   => 'nullable|numeric|min:0|max:30',
            'tax_percent'       => 'nullable|numeric|min:0|max:30',
            'logo'              => 'nullable|file|max:5120',
            'cover'             => 'nullable|file|max:5120',
        ]);

        $values = collect($data)->except(['logo', 'cover'])->map(fn ($v) => (string) ($v ?? ''))->all();
        $values['english_enabled'] = $request->boolean('english_enabled') ? '1' : '0';
        $values['ordering_enabled'] = $request->boolean('ordering_enabled') ? '1' : '0';

        // Colors, fonts and logo are part of the branding feature; without it the EgMenu defaults stay.
        if (!Entitlements::current()->allows('full_branding')) {
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

        return back()->with('ok', __('Settings saved.'));
    }
}
