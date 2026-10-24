<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use App\Support\PlatformBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الهوية والشكل: the platform's site name, logos, favicon and brand colors (App\Support\PlatformBrand). Saved colors
 * reach the marketing site, auth pages and dashboards as CSS variables; "reset" goes back to the EgHub identity.
 */
class BrandingController extends Controller
{
    /** upload field => [setting key, ImageService profile] */
    private const UPLOADS = [
        'logo' => ['brand_logo', 'platform_logo'],
        'logo_on_dark' => ['brand_logo_on_dark', 'platform_logo'],
        'favicon' => ['brand_favicon', 'favicon'],
    ];

    public function edit(): View
    {
        return view('admin.branding', [
            'brand' => PlatformBrand::all(),
            'defaults' => PlatformBrand::DEFAULTS,
            'contrast' => PlatformBrand::contrast(PlatformBrand::get('brand_color_primary'), '#ffffff'),
        ]);
    }

    public function update(Request $request, ImageService $images): RedirectResponse
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'brand_site_name' => 'required|string|max:40',
            'brand_color_primary' => $hex,
            'brand_color_secondary' => $hex,
            'brand_color_navy' => $hex,
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'logo_on_dark' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'favicon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [], [
            'brand_color_primary' => __('Primary color'),
            'brand_color_secondary' => __('Secondary color'),
            'brand_color_navy' => __('Dark color'),
        ]);

        $values = ['brand_site_name' => trim($data['brand_site_name'])];
        foreach (PlatformBrand::COLORS as $key) {
            $values[$key] = strtoupper($data[$key]);
        }
        foreach (self::UPLOADS as $field => [$key, $profile]) {
            $current = PlatformBrand::get($key);
            if ($request->boolean("remove_$field") && $current !== '') {
                $images->delete($current);
                $values[$key] = '';
            }
            if ($request->hasFile($field)) {
                $images->delete($current);
                $values[$key] = $images->store($request->file($field), $profile);
            }
        }
        PlatformBrand::put($values);

        $redirect = back()->with('ok', __('Branding saved. Every page uses it now.'));
        if (PlatformBrand::contrast($values['brand_color_primary'], '#ffffff') < 3) {
            $redirect->with('brand_warning', __('The primary color is light: white text on its buttons is hard to read. Pick a darker shade.'));
        }

        return $redirect;
    }

    /** Back to the EgHub identity: default name and colors, uploaded logos and favicon removed. */
    public function reset(ImageService $images): RedirectResponse
    {
        foreach (self::UPLOADS as [$key]) {
            $images->delete(PlatformBrand::get($key));
        }
        PlatformBrand::put(array_map(fn () => null, PlatformBrand::DEFAULTS));

        return back()->with('ok', __('Back to the EgHub defaults.'));
    }
}
