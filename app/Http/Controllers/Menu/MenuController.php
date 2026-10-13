<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public menu of the current restaurant (tenancy initialised from the URL). */
class MenuController extends Controller
{
    public function show(Request $request): View
    {
        $table = self::currentTable($request);
        $catalog = MenuCatalog::forBranch($table?->branch ?? $this->branch($request));
        $branch = $catalog->branch;
        $categories = $catalog->categories();
        $settings = Settings::all();

        $canOrder = $table !== null && Entitlements::current()->allows('qr_ordering') && $settings['ordering_enabled'] === '1';

        $loyalty = app(\App\Services\Loyalty\LoyaltyService::class)->program();

        return view('menu.show', [
            'loyalty'    => $loyalty,
            'cardToken'  => $loyalty ? $request->cookie('card_' . tenant()->getTenantKey()) : null,
            'feedback'   => Entitlements::current()->allows('feedback'),
            'settings'   => $settings,
            'categories' => $categories,
            'table'      => $table,
            'branch'     => $branch,
            'branches'   => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'canOrder'   => $canOrder,
            'data'       => [
                'tenant'   => tenant()->getTenantKey(),
                'canOrder' => $canOrder,
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'orderUrl' => route('menu.order'),
                'service'  => (float) $settings['service_percent'],
                'tax'      => (float) $settings['tax_percent'],
                'items'    => $categories->flatMap(fn (Category $c) => $c->items)->mapWithKeys(fn (MenuItem $i) => [$i->id => [
                    'id' => $i->id, 'name' => $i->displayName(), 'price' => (float) $i->price, 'soldOut' => $i->is_sold_out,
                    'description' => tr($i->description, $i->description_en), 'image' => $i->image ? '/storage/' . $i->image : null,
                    'calories' => $i->calories,
                    'groups' => $i->optionGroups->map(fn ($g) => [
                        'id' => $g->id, 'name' => tr($g->name, $g->name_en), 'type' => $g->type, 'required' => $g->is_required,
                        'max' => $g->max_choices,
                        'options' => $g->options->map(fn ($o) => [
                            'id' => $o->id, 'name' => tr($o->name, $o->name_en), 'price' => (float) $o->price,
                            'soldOut' => $o->is_sold_out, 'default' => $o->is_default,
                        ])->values(),
                    ])->values(),
                ]]),
            ],
        ]);
    }

    /** QR of a table: remember the table for this restaurant, then show the menu on a clean URL. */
    public function table(Request $request, string $token): RedirectResponse
    {
        $table = DiningTable::query()->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        $request->session()->put($this->tableKey(), ['id' => $table->id, 'at' => now()->timestamp]);
        return redirect()->route('menu.show');
    }

    /** The table scanned in this session within the last 4 hours (after that the guest has surely left). */
    public static function currentTable(Request $request): ?DiningTable
    {
        $saved = $request->session()->get('table.' . tenant()->getTenantKey());
        if (!is_array($saved) || ($saved['at'] ?? 0) < now()->subHours(4)->timestamp) {
            return null;
        }
        return DiningTable::query()->where('is_active', true)->with('branch')->find($saved['id'] ?? 0);
    }

    private function branch(Request $request): ?Branch
    {
        $slug = $request->query('branch');
        return $slug ? Branch::query()->where('is_active', true)->where('slug', $slug)->first() : null;
    }

    private function tableKey(): string
    {
        return 'table.' . tenant()->getTenantKey();
    }
}
