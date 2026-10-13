<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The cashier's own shift (open, cash in/out, close) and, for managers, the shift history. */
class ShiftsController extends Controller
{
    public function index(Request $request, ShiftService $shifts): View
    {
        $current = $shifts->current($request->user());
        $history = $request->user()->can('reports.view')
            ? Shift::query()->with(['user', 'branch'])->whereNotNull('closed_at')->latest('id')->limit(30)->get()
            : Shift::query()->where('user_id', $request->user()->id)->whereNotNull('closed_at')->latest('id')->limit(10)->get();

        return view('dashboard.shifts', [
            'current' => $current,
            'summary' => $current ? $shifts->summary($current) : null,
            'movements' => $current ? $current->movements()->latest('id')->get() : collect(),
            'history' => $history->map(fn (Shift $s) => ['shift' => $s, 'summary' => $shifts->summary($s)]),
            'branch' => PosController::branch($request),
        ]);
    }

    public function open(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['opening_cash' => 'required|numeric|min:0|max:1000000']);
        try {
            $shifts->open($request->user(), PosController::branch($request), (float) $data['opening_cash']);
        } catch (OrderException $e) {
            return back()->withErrors(['shift' => $e->getMessage()]);
        }
        return redirect()->route('dash.pos')->with('ok', __('Shift opened.'));
    }

    public function move(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['type' => 'required|in:in,out', 'amount' => 'required|numeric|gt:0|max:1000000', 'reason' => 'required|string|max:120']);
        $shift = $shifts->current($request->user()) ?? abort(422);
        $shifts->move($shift, $data['type'], (float) $data['amount'], $data['reason'], $request->user());
        return back()->with('ok', __('Saved.'));
    }

    public function close(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['closing_cash' => 'required|numeric|min:0|max:1000000', 'notes' => 'nullable|string|max:300']);
        $shift = $shifts->current($request->user()) ?? abort(422);
        $shift = $shifts->close($shift, (float) $data['closing_cash'], $data['notes'] ?? null);
        $diff = $shifts->summary($shift)['difference'];
        return back()->with('ok', __('Shift closed. Difference: :diff', ['diff' => money($diff)]));
    }
}
