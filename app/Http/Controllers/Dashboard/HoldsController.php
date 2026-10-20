<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\HeldTicket;
use App\Services\Audit;
use App\Services\Orders\OrderService;
use App\Services\Orders\Terminals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Held tickets of the POS ("تعليق"): park the current order with a name or a table note, list the branch's held
 * tickets, resume one on any terminal of the branch (it leaves the list: two terminals never resume the same one),
 * or discard it (pos.hold.discard). Branch-scoped: another branch never sees them.
 */
class HoldsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $holds = HeldTicket::query()->where('branch_id', $branch->id)->with(['user', 'terminal'])->latest('id')->limit(60)->get();

        return response()->json(['holds' => $holds->map(fn (HeldTicket $h) => [
            'id' => $h->id, 'label' => $h->label, 'total' => $h->total, 'count' => $h->items_count, 'by' => $h->user?->name,
            'terminal' => $h->terminal?->code, 'time' => local_time($h->created_at, 'h:i A'), 'mine' => $h->user_id === $request->user()->id,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $data = $request->validate([
            'label' => 'required|string|max:80',
            'total' => 'nullable|numeric|min:0|max:1000000',
            'cart' => 'required|array',
            'cart.type' => ['required', Rule::in(['dine_in', 'takeaway'])],
            'cart.table' => 'nullable',
            'cart.lines' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'cart.lines.*.item_id' => 'required|integer',
            'cart.lines.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'cart.lines.*.option_ids' => 'array|max:30',
            'cart.lines.*.option_ids.*' => 'integer',
            'cart.lines.*.notes' => 'nullable|string|max:200',
            'cart.customer_name' => 'nullable|string|max:100',
            'cart.customer_phone' => 'nullable|string|max:20',
            'cart.notes' => 'nullable|string|max:500',
            'cart.discount' => 'nullable|numeric|min:0',
            'cart.discount_kind' => ['nullable', Rule::in(['amount', 'percent'])],
        ]);
        $cart = $data['cart'];
        $cart['lines'] = array_map(fn ($l) => [
            'item_id' => (int) $l['item_id'], 'qty' => (int) $l['qty'], 'option_ids' => array_map('intval', $l['option_ids'] ?? []),
            'notes' => (string) ($l['notes'] ?? ''),
        ], $cart['lines']);
        $hold = HeldTicket::query()->create([
            'branch_id' => $branch->id, 'terminal_id' => Terminals::fromRequest($request, $branch)?->id, 'user_id' => $request->user()->id,
            'label' => mb_substr(trim($data['label']), 0, 80), 'cart' => $cart, 'total' => round((float) ($data['total'] ?? 0), 2),
            'items_count' => array_sum(array_column($cart['lines'], 'qty')),
        ]);

        return response()->json(['ok' => true, 'id' => $hold->id]);
    }

    /** Takes the ticket off the list and hands its cart back (locked: a second terminal finds it gone). */
    public function resume(Request $request, HeldTicket $held): JsonResponse
    {
        $branch = PosController::branch($request);
        abort_if($held->branch_id !== $branch->id, 404);
        $cart = DB::transaction(function () use ($held) {
            $locked = HeldTicket::query()->lockForUpdate()->find($held->id);
            if (! $locked) {
                return null;
            }
            $locked->delete();

            return $locked->cart;
        });
        if ($cart === null) {
            return response()->json(['message' => __('Another terminal already opened this ticket.')], 422);
        }

        return response()->json(['ok' => true, 'label' => $held->label, 'cart' => $cart]);
    }

    public function discard(Request $request, HeldTicket $held): JsonResponse
    {
        $branch = PosController::branch($request);
        abort_if($held->branch_id !== $branch->id, 404);
        abort_unless($request->user()->can('pos.hold.discard'), 403);
        $held->delete();
        Audit::record('pos.hold_discarded', $held->label, ['total' => $held->total, 'items' => $held->items_count, 'by' => $held->user?->name]);

        return response()->json(['ok' => true]);
    }
}
