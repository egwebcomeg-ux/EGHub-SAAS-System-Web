<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\TvScreen;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Menu board on a TV (Business). The TV keeps the page open all day; it asks for a short fingerprint of what it
 * shows every minute and reloads only when that changes (new price, sold out, an offer starting or ending).
 */
class TvController extends Controller
{
    public function show(string $token): View
    {
        $screen = $this->screen($token);
        $board = $this->board($screen);
        return view('menu.tv', [
            'screen' => $screen, 'categories' => $board, 'settings' => Settings::all(),
            'version' => $this->fingerprint($screen, $board),
        ]);
    }

    public function version(string $token): JsonResponse
    {
        $screen = $this->screen($token);
        return response()->json(['v' => $this->fingerprint($screen, $this->board($screen))]);
    }

    private function screen(string $token): TvScreen
    {
        abort_unless(Entitlements::current()->allows('tv_screens'), 404);
        return TvScreen::query()->where('token', $token)->where('is_active', true)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, Category> */
    private function board(TvScreen $screen)
    {
        $branch = $screen->branch_id ? $screen->branch()->where('is_active', true)->first() : null;
        $categories = MenuCatalog::forBranch($branch)->categories();
        if ($screen->category_ids) {
            $order = array_flip(array_map('intval', $screen->category_ids));
            $categories = $categories->filter(fn (Category $c) => isset($order[$c->id]))->values();
        }
        return $categories;
    }

    private function fingerprint(TvScreen $screen, $categories): string
    {
        $data = $categories->map(fn (Category $c) => [$c->id, $c->name, $c->name_en, $c->items->map(fn (MenuItem $i) => [
            $i->id, $i->name, $i->name_en, $i->description, $i->price, $i->is_sold_out, $i->image, $i->getAttribute('offer_percent'),
        ])]);
        $settings = Settings::all();
        return md5(json_encode([$data, $screen->updated_at?->timestamp, $settings['brand_name'] ?? '', $settings['logo'] ?? '', $settings['color_primary'] ?? '']));
    }
}
