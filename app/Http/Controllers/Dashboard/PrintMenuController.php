<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Menu\MenuCatalog;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable menu (A4), made from the live menu in the restaurant's colors. The browser flows categories into the
 * columns and picks the largest type that fits the chosen number of pages (Scratch Cafe's algorithm); "Save as
 * PDF" in the print dialog gives the PDF. Templates: text-only, or with item photos (Kebab Pasha style).
 */
class PrintMenuController extends Controller
{
    public function show(Request $request): View
    {
        $opts = $request->validate([
            'template' => 'nullable|in:text,photos',
            'pages'    => 'nullable|integer|min:1|max:6',
            'lang'     => 'nullable|in:ar,en,both',
            'branch'   => 'nullable|integer',
            'desc'     => 'nullable|boolean',
        ]);
        $branch = isset($opts['branch']) ? Branch::query()->find($opts['branch']) : null;
        $catalog = MenuCatalog::forBranch($branch)->withoutOffers();
        $settings = Settings::all();

        return view('dashboard.menu.print', [
            'categories' => $catalog->categories(),
            'branch' => $catalog->branch,
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'settings' => $settings,
            'template' => $opts['template'] ?? 'text',
            'pages' => (int) ($opts['pages'] ?? 2),
            'lang' => $opts['lang'] ?? 'both',
            'desc' => (bool) ($opts['desc'] ?? true),
            'qr' => Qr::svg(tenant()->url(), 240, is_hex_color($settings['color_secondary']) ? $settings['color_secondary'] : '#1E2D4F'),
        ]);
    }
}
