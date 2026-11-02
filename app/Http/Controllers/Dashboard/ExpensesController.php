<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Services\Audit;
use App\Services\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The expenses of a month and what the restaurant kept after them. Staff tied to a branch see and write that branch only. */
class ExpensesController extends Controller
{
    public function index(Request $request): View
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) ? (string) $request->query('month') : local_at()->format('Y-m');
        $from = CarbonImmutable::parse($month.'-01');
        $to = $from->endOfMonth();
        $branch = $request->user()->branch_id;

        return view('dashboard.expenses', [
            'month' => $month,
            'profit' => (new ProfitReport($from, $to, $branch))->build(),
            'expenses' => Expense::query()->with(['branch', 'user'])->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))->orderByDesc('spent_on')->orderByDesc('id')->paginate(30)->withQueryString(),
            'branches' => $branch ? collect() : Branch::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'spent_on' => 'required|date_format:Y-m-d',
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'note' => 'nullable|string|max:200',
        ]);
        $expense = Expense::query()->create(['branch_id' => $request->user()->branch_id ?? ($data['branch_id'] ?? null), 'user_id' => $request->user()->id] + $data);
        Audit::record('expense.added', $expense->categoryLabel(), ['amount' => $expense->amount, 'spent_on' => $data['spent_on'], 'branch_id' => $expense->branch_id]);

        return back()->with('ok', __('Expense saved.'));
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        abort_if($request->user()->branch_id && $expense->branch_id !== $request->user()->branch_id, 404);
        Audit::record('expense.deleted', $expense->categoryLabel(), ['amount' => $expense->amount, 'spent_on' => $expense->spent_on->toDateString(), 'branch_id' => $expense->branch_id]);
        $expense->delete();

        return back()->with('ok', __('Expense deleted.'));
    }
}
