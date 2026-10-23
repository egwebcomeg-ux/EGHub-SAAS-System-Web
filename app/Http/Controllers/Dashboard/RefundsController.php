<?php

namespace App\Http\Controllers\Dashboard;

use App\Billing\PaymentMethods;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\RefundService;
use App\Services\Orders\ShiftService;
use App\Services\Orders\Terminals;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The POS "Orders" window: the branch's recent paid orders (search by number or by the offline reference
 * OFF-K1-3), and refunds / returns of one of them with a manager's approval, then the refund slip.
 */
class RefundsController extends Controller
{
    public function recent(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $q = trim((string) $request->query('q', ''));
        $number = ltrim($q, '#');
        $orders = Order::query()->where('branch_id', $branch->id)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')
            ->when($q === '', fn ($w) => $w->where('created_at', '>=', now()->subDays(2)))
            ->when($q !== '', fn ($w) => ctype_digit($number) ? $w->where('number', (int) $number) : $w->where('offline_ref', 'like', '%'.mb_substr($q, 0, 24).'%'))
            ->with('items')->latest('id')->limit(30)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => [
            'id' => $o->id, 'number' => $o->number, 'ref' => $o->reference(), 'offline_ref' => $o->offline_ref,
            'total' => $o->total, 'refunded' => $o->refunded_amount, 'refund_status' => $o->refund_status,
            'method' => PaymentMethods::label($o->payment_method ?? 'cash'), 'paid_by' => $o->payment_method,
            'corporate' => $o->corporate_amount, 'customer' => (bool) $o->customer_id, 'factor' => $o->subtotal > 0 ? $o->total / $o->subtotal : 0,
            'time' => local_time($o->created_at, $o->created_at->isToday() ? 'h:i A' : 'd/m h:i A'),
            'receipt' => route('dash.orders.receipt', $o), 'share' => $o->receiptUrl(), 'phone' => $o->customer_phone,
            // the guest pager: "Call" on an order still open at the counter (null without the pager or for a table / delivery)
            'pager' => in_array($o->status, ['served', 'cancelled'], true) ? null : PagerController::orderView($o, $request),
            'items' => $o->items->map(fn (OrderItem $i) => [
                'id' => $i->id, 'name' => $i->name, 'qty' => $i->quantity, 'left' => $i->quantity - $i->refunded_qty, 'unit' => $i->unit_price,
            ])->values(),
        ])->values()]);
    }

    public function store(Request $request, Order $order, RefundService $refunds, ShiftService $shifts): JsonResponse
    {
        $branch = PosController::branch($request);
        abort_if($order->branch_id !== $branch->id, 404);
        $data = $request->validate([
            'full' => 'required|boolean',
            'lines' => 'array|max:'.OrderService::MAX_LINES,
            'lines.*.order_item_id' => 'required|integer',
            'lines.*.qty' => 'required|integer|min:0|max:'.OrderService::MAX_QTY,
            'method' => ['nullable', Rule::in(RefundService::METHODS)],
            'reason' => 'required|string|max:200',
            'approver_id' => 'nullable|integer',
            'pin' => 'nullable|string|max:12',
        ]);
        try {
            $refund = $refunds->refund($order, $request->user(), $shifts->current($request->user()), Terminals::fromRequest($request, $branch), $data);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true, 'id' => $refund->id, 'amount' => $refund->amount, 'methods' => $refund->methods,
            'status' => $order->fresh()->refund_status, 'receipt' => route('dash.pos.refunds.receipt', $refund),
        ]);
    }

    /** The refund slip (80mm): what came back, how the money went back, who approved. */
    public function receipt(Request $request, OrderRefund $refund): View
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $refund->branch_id, 404);

        return view('dashboard.pos.refund-receipt', [
            'refund' => $refund->load(['order', 'branch', 'approver', 'creator', 'terminal']), 'settings' => Settings::all(),
        ]);
    }
}
