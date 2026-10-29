<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Suppliers (name, phone, notes) and each one's statement of purchases. Payments to suppliers are not tracked. */
class SuppliersController extends Controller
{
    public function index(): View
    {
        return view('dashboard.inventory.suppliers', [
            'suppliers' => Supplier::query()->orderByDesc('is_active')->orderBy('name')
                ->withSum(['purchases as purchased' => fn ($q) => $q->where('status', 'posted')], 'total')
                ->withMax(['purchases as last_purchase' => fn ($q) => $q->where('status', 'posted')], 'purchased_on')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::query()->create($this->validated($request));

        return back()->with('ok', __('Supplier saved.'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('Supplier saved.'));
    }

    /**
     * Every purchase of the supplier in the period (all branches, or the staff member's own), with the running total
     * of the posted ones; cancelled ones are listed crossed out. ?format=csv|xlsx downloads it.
     */
    public function statement(Request $request, Supplier $supplier): View|Response
    {
        $from = rescue(fn () => $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from')) : null, null, false);
        $to = rescue(fn () => $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to')) : null, null, false);
        $own = $request->user()->branch_id;
        $purchases = Purchase::query()->where('supplier_id', $supplier->id)->whereIn('status', ['posted', 'cancelled'])
            ->with(['branch:id,name,name_en', 'canceller:id,name'])
            ->when($own, fn ($q) => $q->where('branch_id', $own))
            ->when($from, fn ($q) => $q->whereDate('purchased_on', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('purchased_on', '<=', $to->toDateString()))
            ->orderBy('purchased_on')->orderBy('id')->get();
        $running = 0.0;
        $purchases->each(function (Purchase $p) use (&$running) {
            $running += $p->isPosted() ? $p->total : 0;
            $p->setAttribute('running', round($running, 2));
        });

        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            $rows = [[__('Date'), __('Reference'), __('Branch'), __('Status'), __('Total'), __('Running total')]];
            foreach ($purchases as $p) {
                $rows[] = [$p->purchased_on->format('Y-m-d'), $p->reference, $p->branch ? tr($p->branch->name, $p->branch->name_en) : '', $p->statusLabel(),
                    $p->total, $p->running];
            }
            $rows[] = [__('Total'), '', '', '', round($running, 2), ''];

            return CorporateContractsController::download($rows, 'supplier-'.$supplier->id.'-'.now()->format('Y-m-d'), (string) $request->query('format'), __('Statement'));
        }

        return view('dashboard.inventory.supplier', [
            'supplier' => $supplier, 'purchases' => $purchases, 'total' => round($running, 2), 'from' => $from, 'to' => $to,
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
