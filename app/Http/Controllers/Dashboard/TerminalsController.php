<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use App\Services\Orders\Terminals;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The owner / manager names the POS terminals of each branch ("كاشير 1", K1), frees a device's claim and sets how
 * long a device may sell offline. Shows which device holds each terminal and its open shift.
 */
class TerminalsController extends Controller
{
    public function index(Request $request): View
    {
        $branches = Branch::query()->orderBy('sort_order')->get();
        $terminals = PosTerminal::query()->with(['branch', 'claimer'])->orderBy('branch_id')->orderBy('code')->get();
        $open = Shift::query()->whereNull('closed_at')->whereIn('terminal_id', $terminals->pluck('id'))->with('user')->get()->keyBy('terminal_id');

        return view('dashboard.pos.terminals', [
            'branches' => $branches, 'terminals' => $terminals, 'open' => $open, 'offlineHours' => Terminals::offlineHours(),
        ]);
    }

    public function store(Request $request, Terminals $terminals): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'name' => 'required|string|max:60', 'code' => 'nullable|string|max:8',
        ]);
        try {
            $terminals->create(Branch::query()->findOrFail($data['branch_id']), $data['name'], $data['code'] ?? null);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('ok', __('Terminal added.'));
    }

    public function update(Request $request, PosTerminal $terminal): RedirectResponse
    {
        $this->ownBranch($request, $terminal);
        $data = $request->validate(['name' => 'required|string|max:60', 'is_active' => 'nullable|boolean']);
        $terminal->update(['name' => mb_substr(trim($data['name']), 0, 60), 'is_active' => $request->boolean('is_active')]);
        Audit::record('pos.terminal_updated', $terminal->label(), ['active' => $terminal->is_active]);

        return back()->with('ok', __('Saved.'));
    }

    public function release(Request $request, PosTerminal $terminal, Terminals $terminals): RedirectResponse
    {
        $this->ownBranch($request, $terminal);
        $terminals->release($terminal);

        return back()->with('ok', __('The device was signed off this terminal.'));
    }

    /** A manager tied to one branch only touches that branch's terminals (the route is already tenant-scoped). */
    private function ownBranch(Request $request, PosTerminal $terminal): void
    {
        $user = $request->user();
        abort_if($user->branch_id && $user->branch_id !== $terminal->branch_id, 404);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['pos_offline_hours' => 'required|integer|min:1|max:72']);
        Settings::put(['pos_offline_hours' => (string) $data['pos_offline_hours']]);
        Audit::record('pos.offline_hours', (string) $data['pos_offline_hours']);

        return back()->with('ok', __('Saved.'));
    }
}
