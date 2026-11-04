<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Menu\MenuCatalog;
use App\Services\Qr;
use App\Services\Settings;
use App\Support\MenuLocales;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Printable menu (A4), made from the live menu in the restaurant's colors. The browser flows categories into the
 * columns and picks the largest type that fits the chosen number of pages (Scratch Cafe's algorithm); "Save as
 * PDF" in the print dialog gives the PDF. Templates: text-only, with item photos (Kebab Pasha style), or cards: A4
 * landscape, four rounded panels in the brand's dark colour and white, each size on its own line (Tayebat El-Wadi style).
 */
class PrintMenuController extends Controller
{
    public function show(Request $request): View
    {
        $extra = MenuLocales::enabled();
        $opts = $request->validate([
            'template' => 'nullable|in:text,photos,cards',
            'pages' => 'nullable|integer|min:1|max:6',
            // ar, en, both (Arabic + English), an extra language "fr", or Arabic + an extra language "ar-fr"
            'lang' => ['nullable', Rule::in(array_merge(['ar', 'en', 'both'], $extra, array_map(fn ($c) => 'ar-'.$c, $extra)))],
            'branch' => 'nullable|integer',
            'desc' => 'nullable|boolean',
        ]);
        $lang = $opts['lang'] ?? 'both';
        [$mainLang, $secondLang] = match (true) {
            $lang === 'both' => ['ar', 'en'],
            str_starts_with($lang, 'ar-') => ['ar', substr($lang, 3)],
            default => [$lang, null],
        };
        $branch = isset($opts['branch']) ? Branch::query()->find($opts['branch']) : null;
        $catalog = MenuCatalog::forBranch($branch)->withoutOffers()->allDay();
        // the chosen template is remembered, so the restaurant's own look opens by itself next time
        $template = $opts['template'] ?? Settings::get('print_template');
        if (isset($opts['template']) && $opts['template'] !== Settings::get('print_template')) {
            Settings::put(['print_template' => $opts['template']]);
        }
        $settings = Settings::all();

        return view('dashboard.menu.print', [
            'categories' => $catalog->categories(),
            'branch' => $catalog->branch,
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'settings' => $settings,
            'template' => in_array($template, ['text', 'photos', 'cards'], true) ? $template : 'text',
            'pages' => (int) ($opts['pages'] ?? 2),
            'lang' => $lang,
            'mainLang' => $mainLang,
            'secondLang' => $secondLang,
            'extraLocales' => $extra,
            'desc' => (bool) ($opts['desc'] ?? true),
            'qr' => Qr::svg(tenant()->url(), 240, is_hex_color($settings['color_secondary']) ? $settings['color_secondary'] : '#0A2540'),
        ]);
    }
}
