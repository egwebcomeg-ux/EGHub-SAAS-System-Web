<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\SiteModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Marketing site: the module index (/features) and one page per module (/features/{slug}). */
class FeaturesController extends Controller
{
    public function index(): View
    {
        return view('landing.features.index', SiteModules::pricing());
    }

    public function show(string $slug): View|RedirectResponse
    {
        // modules that were merged into another one keep their old address
        if (isset(SiteModules::MERGED[$slug])) {
            return redirect()->route('features.show', SiteModules::MERGED[$slug], 301);
        }
        $module = SiteModules::find($slug);
        abort_if($module === null, 404);
        [$previous, $next] = SiteModules::neighbours($slug);
        $pricing = SiteModules::pricing();

        return view('landing.features.show', $pricing + [
            'module' => $module,
            'previous' => $previous,
            'next' => $next,
            'availability' => SiteModules::availability($module, $pricing['plans'], $pricing['matrix'], $pricing['addons']),
            'related' => array_values(array_filter(array_map(fn (string $s) => SiteModules::find($s), $module['related']))),
        ]);
    }
}
