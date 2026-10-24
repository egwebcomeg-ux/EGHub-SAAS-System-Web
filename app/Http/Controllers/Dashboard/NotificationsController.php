<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TableCall;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Orders\OrderConfirmation;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell in the dashboard header, polled every 20 seconds. Built from what already happens (no extra events): new
 * orders, orders turned ready, waiter calls and bill requests, booking requests and ingredients under their minimum,
 * each only with its plan feature and the user's right, in the user's branch. Opening the list marks it read
 * (users.notifications_seen_at).
 */
class NotificationsController extends Controller
{
    public const WINDOW_HOURS = 12;

    public const LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $seen = $user->notifications_seen_at;
        $items = collect($this->items($user))->sortByDesc('at')->take(self::LIMIT)->values()
            ->map(fn (array $i) => $i + ['unread' => ! $seen || $i['at']->gt($seen)]);

        return response()->json([
            'unread' => $items->where('unread', true)->count(),
            'items' => $items->map(fn (array $i) => ['at' => $i['at']->toIso8601String(), 'time' => local_time($i['at'], 'h:i A')] + $i)->values(),
        ]);
    }

    public function seen(Request $request): JsonResponse
    {
        $request->user()->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** @return list<array{key:string, kind:string, icon:string, title:string, detail:string, at:CarbonInterface, url:string}> */
    private function items(User $user): array
    {
        $rights = Entitlements::current();
        $since = now()->subHours(self::WINDOW_HOURS);
        $branch = $user->branch_id;
        $items = [];

        if ($rights->allows('order_board') && $user->can('orders.view')) {
            $board = route('dash.orders');
            $orders = Order::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('ready_at', '>=', $since))
                ->with('table')->latest('id')->limit(self::LIMIT * 2)->get();
            foreach ($orders as $o) {
                $where = $o->table ? __('Table :name', ['name' => $o->table->name]) : (Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type);
                // a cashier does not need to hear about the order they just rang up
                if ($o->created_at->gte($since) && $o->created_by !== $user->id) {
                    $items[] = ['key' => "order-{$o->id}", 'kind' => 'order', 'icon' => '🧾', 'title' => __('New order #:n', ['n' => $o->number]),
                        'detail' => $where, 'at' => $o->created_at, 'url' => $board];
                }
                if ($o->ready_at && $o->ready_at->gte($since) && $o->status !== 'cancelled') {
                    $items[] = ['key' => "ready-{$o->id}", 'kind' => 'ready', 'icon' => '✅', 'title' => __('Order #:n is ready', ['n' => $o->number]),
                        'detail' => $where, 'at' => $o->ready_at, 'url' => $board];
                }
            }

            // the guest answered the WhatsApp confirmation of a cash-on-delivery order (OrderConfirmation)
            $answers = Order::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where('confirm_replied_at', '>=', $since)->where('confirm_via', '!=', 'phone')
                ->latest('confirm_replied_at')->limit(self::LIMIT)->get();
            foreach ($answers as $o) {
                $state = OrderConfirmation::state($o);
                $items[] = ['key' => "confirm-{$o->id}", 'kind' => 'confirm', 'icon' => $state === 'confirmed' ? '👍' : ($state === 'cancel_requested' ? '✋' : '✏️'),
                    'title' => __('Order #:n', ['n' => $o->number]).' · '.OrderConfirmation::label((string) $state),
                    'detail' => trim($o->customer_name.' '.$o->customer_phone), 'at' => $o->confirm_replied_at, 'url' => $board];
            }

            $tables = $user->can('orders.manage') ? route('dash.orders.tables') : $board;
            $calls = TableCall::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))->where('created_at', '>=', $since)
                ->with('table')->latest('id')->limit(self::LIMIT)->get();
            foreach ($calls as $c) {
                $items[] = ['key' => "call-{$c->id}", 'kind' => 'call', 'icon' => $c->kind === 'bill' ? '💵' : '🛎',
                    'title' => $c->kindLabel(), 'detail' => __('Table :name', ['name' => $c->table?->name ?? '—']).($c->handled_at ? ' · '.__('Done') : ''),
                    'at' => $c->created_at, 'url' => $tables];
            }
        }

        if ($rights->allows('reservations') && $user->can('reservations.manage')) {
            $bookings = Reservation::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where('status', 'pending')->where('created_at', '>=', $since)->latest('id')->limit(self::LIMIT)->get();
            foreach ($bookings as $r) {
                $items[] = ['key' => "booking-{$r->id}", 'kind' => 'booking', 'icon' => '📅', 'title' => __('Booking request'),
                    'detail' => $r->name.' · '.__(':n guests', ['n' => $r->party_size]).' · '.local_time($r->starts_at, $r->starts_at->isToday() ? 'h:i A' : 'd/m h:i A'),
                    'at' => $r->created_at, 'url' => route('dash.reservations')];
            }
        }

        if ($rights->allows('inventory') && $user->can('inventory.manage')) {
            // listed while it stays low (not only within the window), dated by its last stock change
            $low = IngredientStock::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->whereHas('ingredient', fn ($q) => $q->where('is_active', true)->where('min_stock', '>', 0)->whereColumn('ingredient_stocks.quantity', '<=', 'ingredients.min_stock'))
                ->with('ingredient')->latest('updated_at')->limit(self::LIMIT)->get();
            foreach ($low as $s) {
                $items[] = ['key' => "stock-{$s->id}", 'kind' => 'stock', 'icon' => '📦', 'title' => __('Low stock: :name', ['name' => $s->ingredient->name]),
                    'detail' => __('On hand: :qty', ['qty' => rtrim(rtrim(number_format($s->quantity, 3, '.', ''), '0'), '.').' '.$s->ingredient->unit]),
                    'at' => $s->updated_at, 'url' => route('dash.inventory')];
            }
        }

        return $items;
    }

    /** Whether the bell is worth showing to this user at all. */
    public static function visibleFor(User $user): bool
    {
        $rights = Entitlements::current();

        return ($rights->allows('order_board') && $user->can('orders.view'))
            || ($rights->allows('reservations') && $user->can('reservations.manage'))
            || ($rights->allows('inventory') && $user->can('inventory.manage'));
    }
}
