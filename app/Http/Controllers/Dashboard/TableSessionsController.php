<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableCall;
use App\Models\TableSession;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The "Tables" tab of the orders board: every table free or busy (time since it opened, total so far, orders,
 * waiter calls), the combined bill of a table, one payment for all its orders and "close table" that frees it.
 * Staff tied to a branch only see that branch's tables.
 */
class TableSessionsController extends Controller
{
    public function index(Request $request): View
    {
        return view('dashboard.orders.tables', [
            'poll' => OrdersController::POLL_SECONDS * 2,
            'methods' => array_values(array_diff(ShiftService::METHODS, [WalletService::METHOD])),
            'canPay' => $request->user()->can('pos.use'),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $branch = $request->user()->branch_id;
        $tables = DiningTable::query()->where('is_active', true)->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('id')->limit(300)->get();
        $sessions = TableSession::query()->open()->whereIn('dining_table_id', $tables->pluck('id'))
            ->with(['orders' => fn ($q) => $q->where('status', '!=', 'cancelled')])->get()->keyBy('dining_table_id');
        $calls = TableCall::query()->whereNull('handled_at')->whereIn('dining_table_id', $tables->pluck('id'))->get()->groupBy('dining_table_id');
        $manyBranches = $tables->pluck('branch_id')->unique()->count() > 1;

        return response()->json(['tables' => $tables->map(function (DiningTable $t) use ($sessions, $calls, $manyBranches) {
            $session = $sessions->get($t->id);
            $tableCalls = $calls->get($t->id, collect());

            return [
                'id' => $t->id, 'name' => $t->name, 'branch' => $manyBranches && $t->branch ? tr($t->branch->name, $t->branch->name_en) : null,
                'calls' => $tableCalls->where('kind', 'waiter')->count(), 'bill' => $tableCalls->where('kind', 'bill')->isNotEmpty(),
                'session' => $session ? [
                    'id' => $session->id, 'opened_at' => $session->opened_at->toIso8601String(), 'time' => local_time($session->opened_at, 'h:i A'),
                    'total' => money($session->total), 'orders' => $session->orders->count(),
                    'unpaid' => $session->orders->where('payment_status', '!=', 'paid')->count(),
                    'in_kitchen' => $session->orders->whereIn('status', ['new', 'preparing'])->count(),
                    'ready' => $session->orders->whereIn('status', ['ready', 'on_way'])->count(),
                ] : null,
            ];
        })->values()]);
    }

    /** The combined bill of a table (JSON for the close-table sheet). */
    public function bill(Request $request, TableSession $tableSession, OrderService $orders): JsonResponse
    {
        abort_unless($this->visible($request, $tableSession), 404);

        return response()->json($this->present($tableSession, $orders));
    }

    public function pay(Request $request, TableSession $tableSession, OrderService $orders, ShiftService $shifts): JsonResponse
    {
        abort_unless($this->visible($request, $tableSession), 404);
        $method = $request->validate(['method' => ['required', Rule::in(ShiftService::METHODS)]])['method'];
        try {
            $paid = $orders->paySession($tableSession, $method, $request->user(), $shifts->current($request->user()));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'paid_orders' => $paid, 'bill' => $this->present($tableSession->fresh(), $orders)]);
    }

    public function close(Request $request, TableSession $tableSession, OrderService $orders): JsonResponse
    {
        abort_unless($this->visible($request, $tableSession), 404);
        try {
            $orders->closeSession($tableSession, $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::record('table.closed', $tableSession->table?->name ?? '', ['session' => $tableSession->id, 'total' => $tableSession->total]);

        return response()->json(['ok' => true]);
    }

    /** One 80mm receipt for every order of the table. */
    public function receipt(Request $request, TableSession $tableSession, OrderService $orders): View
    {
        abort_unless($this->visible($request, $tableSession), 404);

        return view('dashboard.orders.session-receipt', [
            'session' => $tableSession->load(['table', 'branch']),
            'bill' => $orders->sessionBill($tableSession),
            'settings' => Settings::all(),
        ]);
    }

    private function visible(Request $request, TableSession $session): bool
    {
        return ! $request->user()->branch_id || $request->user()->branch_id === $session->branch_id;
    }

    /** @return array<string,mixed> */
    private function present(TableSession $session, OrderService $orders): array
    {
        $bill = $orders->sessionBill($session);
        $session->loadMissing('table');

        return [
            'id' => $session->id, 'table' => $session->table?->name, 'open' => $session->isOpen(),
            'opened' => local_time($session->opened_at, 'h:i A'), 'opened_at' => $session->opened_at->toIso8601String(),
            'orders' => $bill['orders']->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->statusLabel(),
                'paid' => $o->payment_status === 'paid', 'total' => money($o->total), 'time' => local_time($o->created_at, 'h:i A'),
            ])->values(),
            'lines' => array_map(fn (array $l) => ['name' => $l['name'], 'options' => $l['options'], 'qty' => $l['qty'], 'total' => money($l['total'])], $bill['lines']),
            'subtotal' => money($bill['subtotal']), 'discount' => $bill['discount'] > 0 ? money($bill['discount']) : null,
            'service' => $bill['service'] > 0 ? money($bill['service']) : null, 'tax' => $bill['tax'] > 0 ? money($bill['tax']) : null,
            'total' => money($bill['total']), 'paid' => money($bill['paid']), 'due' => money($bill['due']), 'due_amount' => $bill['due'],
            'unpaid' => $bill['unpaid'], 'in_kitchen' => $bill['in_kitchen'],
            'receipt' => route('dash.orders.tables.receipt', $session),
        ];
    }
}
