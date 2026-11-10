<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use App\Services\Orders\Terminals;
use App\Services\Orders\WaiterCash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The cashier's own shift (open, cash in/out, close) and, for managers, the shift history. */
class ShiftsController extends Controller
{
    public function index(Request $request, ShiftService $shifts): View
    {
        $current = $shifts->current($request->user());
        // managers see every closed shift of their branch, all branches when tied to none (or one terminal's, ?terminal=); a cashier sees their own
        $terminal = $request->user()->can('reports.view') && $request->filled('terminal') ? (int) $request->query('terminal') : null;
        $history = $request->user()->can('reports.view')
            ? Shift::query()->with(['user', 'branch', 'terminal'])->whereNotNull('closed_at')->when($request->user()->branch_id, fn ($q, $branch) => $q->where('branch_id', $branch))->when($terminal, fn ($q) => $q->where('terminal_id', $terminal))->latest('id')->limit(30)->get()
            : Shift::query()->with('terminal')->where('user_id', $request->user()->id)->whereNotNull('closed_at')->latest('id')->limit(10)->get();

        return view('dashboard.shifts', [
            'current' => $current,
            'summary' => $current ? $shifts->summary($current) : null,
            'movements' => $current ? $current->movements()->latest('id')->get() : collect(),
            'history' => $history->map(fn (Shift $s) => ['shift' => $s, 'summary' => $shifts->summary($s)]),
            'branch' => PosController::branch($request),
            'terminals' => $request->user()->can('reports.view') ? PosTerminal::query()->orderBy('code')->get() : collect(),
            'terminal' => $terminal,
            // waiters holding the cash of their tables, taken into this shift with "received"
            'waiterCash' => $current ? WaiterCash::holders($current->branch_id) : collect(),
        ]);
    }

    /** The cashier took a waiter's cash: their orders join the open shift and its drawer counts the money. */
    public function settleWaiter(Request $request, User $waiter, ShiftService $shifts, WaiterCash $waiterCash): RedirectResponse
    {
        $shift = $shifts->current($request->user());
        if (! $shift) {
            return back()->withErrors(['shift' => __('Open a shift first.')]);
        }
        try {
            $result = $waiterCash->settle($waiter, $request->user(), $shift);
        } catch (OrderException $e) {
            return back()->withErrors(['shift' => $e->getMessage()]);
        }

        return back()->with('ok', __(':name handed in :amount for :n orders.', ['name' => $waiter->name, 'amount' => money($result['amount']), 'n' => $result['orders']]));
    }

    public function open(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['opening_cash' => 'required|numeric|min:0|max:1000000']);
        try {
            $branch = PosController::branch($request);
            $shifts->open($request->user(), $branch, (float) $data['opening_cash'], Terminals::fromRequest($request, $branch));
        } catch (OrderException $e) {
            return back()->withErrors(['shift' => $e->getMessage()]);
        }

        return redirect()->route('dash.pos')->with('ok', __('Shift opened.'));
    }

    public function move(Request $request, ShiftService $shifts): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:in,out', 'amount' => 'required|numeric|gt:0|max:1000000', 'reason' => 'required|string|max:120',
            'category' => ['nullable', Rule::in(array_keys(CashMovement::CATEGORIES))],
        ]);
        $shift = $shifts->current($request->user());
        if (! $shift) {
            return $request->expectsJson() ? response()->json(['message' => __('Open a shift first.')], 422) : abort(422);
        }
        $move = $shifts->move($shift, $data['type'], (float) $data['amount'], $data['reason'], $request->user(), $data['category'] ?? null);
        Audit::record('shift.cash_'.$move->type, $move->reason, ['amount' => $move->amount, 'category' => $move->category, 'shift' => $shift->id]);
        if ($request->expectsJson()) {
            // the POS drawer window: the new expected cash at once
            return response()->json(['ok' => true, 'expected_cash' => $shifts->summary($shift)['expected_cash']]);
        }

        return back()->with('ok', __('Saved.'));
    }

    public function close(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['closing_cash' => 'required|numeric|min:0|max:1000000', 'notes' => 'nullable|string|max:300']);
        $shift = $shifts->current($request->user()) ?? abort(422);
        try {
            $shift = $shifts->close($shift, (float) $data['closing_cash'], $data['notes'] ?? null);
        } catch (OrderException $e) {
            // e.g. a driver or a waiter still holds cash of this branch: said on the page, the shift stays open
            return back()->withInput()->withErrors(['shift' => $e->getMessage()]);
        }
        $diff = $shifts->summary($shift)['difference'];

        return back()->with('ok', __('Shift closed. Difference: :diff', ['diff' => money($diff)]));
    }
}
