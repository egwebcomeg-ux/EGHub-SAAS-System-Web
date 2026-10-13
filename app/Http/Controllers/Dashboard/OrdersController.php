<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Live order board: columns new → preparing → ready (+ served today). The page polls /orders/feed every few
 * seconds (no websockets yet) and chimes when a new order arrives. Staff tied to a branch only see that branch.
 */
class OrdersController extends Controller
{
    public const POLL_SECONDS = 5;

    public function board(): View
    {
        return view('dashboard.orders.board', ['poll' => self::POLL_SECONDS]);
    }

    public function feed(Request $request): JsonResponse
    {
        $orders = $this->scoped($request)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])
                ->orWhere(fn ($q) => $q->whereIn('status', ['served', 'cancelled'])->where('updated_at', '>=', now()->subHours(3))))
            ->with(['items', 'table', 'branch'])->orderBy('id')->limit(200)->get();

        return response()->json([
            'max_id' => (int) $this->scoped($request)->max('id'),
            'orders' => $orders->map(fn (Order $o) => $this->present($o))->values(),
        ]);
    }

    public function transition(Request $request, Order $order, OrderService $service): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))], 'reason' => 'nullable|string|max:200']);
        try {
            $service->transition($order, $data['status'], $request->user(), $data['reason'] ?? null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'order' => $this->present($order->load(['items', 'table', 'branch']))]);
    }

    /** 80mm receipt for the thermal printer (customer copy, or kitchen copy without prices). */
    public function receipt(Request $request, Order $order): View
    {
        abort_unless($this->visible($request, $order), 404);
        return view('dashboard.orders.receipt', [
            'order' => $order->load(['items', 'table', 'branch']),
            'settings' => \App\Services\Settings::all(),
            'kitchen' => $request->query('copy') === 'kitchen',
        ]);
    }

    private function scoped(Request $request)
    {
        $branch = $request->user()->branch_id;
        return Order::query()->when($branch, fn ($q) => $q->where('branch_id', $branch));
    }

    private function visible(Request $request, Order $order): bool
    {
        return !$request->user()->branch_id || $request->user()->branch_id === $order->branch_id;
    }

    /** @return array<string,mixed> */
    private function present(Order $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->statusLabel(),
            'type' => $o->type, 'type_label' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
            'source' => $o->source, 'table' => $o->table?->name, 'branch' => $o->branch ? tr($o->branch->name, $o->branch->name_en) : null,
            'customer' => trim($o->customer_name . ' ' . $o->customer_phone), 'notes' => $o->notes,
            'total' => money($o->total), 'created_at' => $o->created_at->toIso8601String(),
            'time' => local_time($o->created_at, 'h:i A'),
            'next' => array_values(array_filter(Order::TRANSITIONS[$o->status] ?? [], fn ($s) => $o->canMoveTo($s))),
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $i->quantity, 'notes' => $i->notes,
                'options' => collect($i->options ?? [])->pluck('name')->implode('، '),
            ])->values(),
        ];
    }
}
