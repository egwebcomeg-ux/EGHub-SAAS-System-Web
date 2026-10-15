<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-order kiosk (kiosk pack): a tablet left open on {menu}/kiosk/{token}. Guests build their order, type their
 * name, get the order number and pay at the cashier. Orders go through OrderService (prices recomputed there) with
 * source kiosk, in the kiosk's branch, so they show on the order board, kitchen display and POS like any other.
 */
class KioskController extends Controller
{
    public function show(string $token): View
    {
        $kiosk = $this->kiosk($token);
        $settings = Settings::all();
        $categories = MenuCatalog::forBranch($kiosk->branch)->categories()->filter(fn (Category $c) => $c->items->isNotEmpty())->values();

        return view('menu.kiosk', [
            'kiosk' => $kiosk,
            'settings' => $settings,
            'data' => [
                'orderUrl' => route('menu.kiosk.order', $kiosk->token),
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'service' => (float) $settings['service_percent'], 'tax' => (float) $settings['tax_percent'],
                'options' => $kiosk->options(),
                'categories' => $categories->map(fn (Category $c) => [
                    'id' => $c->id, 'name' => tr($c->name, $c->name_en), 'image' => $c->image ? '/storage/'.$c->image : null,
                ])->values(),
                'items' => $categories->flatMap(fn (Category $c) => $c->items->map(fn (MenuItem $i) => [
                    'id' => $i->id, 'category' => $c->id, 'name' => $i->displayName(), 'price' => (float) $i->price,
                    'offerFrom' => $i->getAttribute('offer_from'), 'soldOut' => $i->is_sold_out,
                    'description' => tr((string) $i->description, (string) $i->description_en),
                    'image' => $i->image ? '/storage/'.$i->image : null,
                    'groups' => $i->optionGroups->map(fn ($g) => [
                        'id' => $g->id, 'name' => tr($g->name, $g->name_en), 'type' => $g->type, 'required' => $g->is_required, 'max' => $g->max_choices,
                        'options' => $g->options->map(fn ($o) => [
                            'id' => $o->id, 'name' => tr($o->name, $o->name_en), 'price' => (float) $o->price, 'soldOut' => $o->is_sold_out, 'default' => $o->is_default,
                        ])->values(),
                    ])->values(),
                ]))->values(),
            ],
        ]);
    }

    public function order(Request $request, string $token, OrderService $orders): JsonResponse
    {
        $kiosk = $this->kiosk($token);
        $options = $kiosk->options();
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'type' => ['required', Rule::in($options['order_types'])],
            'table' => 'nullable|string|max:20',
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:200',
            'customer_name' => 'required|string|max:60',
            'customer_phone' => [$options['require_phone'] ? 'required' : 'nullable', 'string', 'max:20'],
        ], [], ['customer_name' => __('Your name'), 'customer_phone' => __('Mobile number')]);

        // dine-in: the guest types the number on the table stand; a known table is linked, anything else is a note
        $table = null;
        $tableText = trim((string) ($data['table'] ?? ''));
        if ($data['type'] === 'dine_in') {
            if ($tableText === '') {
                return response()->json(['message' => __('Write your table number.')], 422);
            }
            $table = DiningTable::query()->where('branch_id', $kiosk->branch_id)->where('is_active', true)->where('name', $tableText)->first();
        }

        try {
            $order = $orders->place([
                'client_uuid' => $data['client_uuid'], 'items' => $data['items'], 'type' => $data['type'], 'source' => 'kiosk',
                'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'] ?? '',
                'notes' => $data['type'] === 'dine_in' && ! $table ? __('Table :name', ['name' => $tableText]) : null,
            ], $kiosk->branch, $table);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true, 'number' => $order->number, 'total' => money($order->total),
            'type' => Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? $order->type,
            'time' => local_time($order->created_at, 'h:i A'), 'name' => $order->customer_name,
            'items' => $order->items()->get()->map(fn ($i) => ['name' => $i->name, 'qty' => $i->quantity])->values(),
        ]);
    }

    /** The kiosk behind a token: the pack must be on and the kiosk and its branch active, otherwise 404. */
    private function kiosk(string $token): Kiosk
    {
        abort_unless(Entitlements::current()->allows('kiosk'), 404);
        $kiosk = Kiosk::query()->where('token', $token)->where('is_active', true)->with('branch')->firstOrFail();
        abort_unless($kiosk->branch?->is_active, 404);

        return $kiosk;
    }
}
