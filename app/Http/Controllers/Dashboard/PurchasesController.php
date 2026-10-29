<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Services\Audit;
use App\Services\Inventory\StockService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Purchase invoices from suppliers: typed as a draft, then posted into the branch stock (StockService::postPurchase,
 * which also moves each ingredient's weighted-average cost). A posted purchase is read-only; only a manager cancels it.
 */
class PurchasesController extends Controller
{
    private const MAX_LINES = 40;

    public function index(Request $request): View
    {
        $own = $request->user()->branch_id;
        $query = Purchase::query()->with(['supplier:id,name', 'branch:id,name,name_en', 'creator:id,name'])->withCount('lines')
            ->when($own, fn ($q) => $q->where('branch_id', $own))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->integer('supplier')))
            ->when(isset(Purchase::STATUSES[$request->query('status')]), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('purchased_on')->orderByDesc('id');

        return view('dashboard.inventory.purchases', [
            'purchases' => $query->paginate(30)->withQueryString(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Purchase(['purchased_on' => now(), 'branch_id' => InventoryController::currentBranch($request)->id,
            'supplier_id' => $request->integer('supplier') ?: null]));
    }

    /** Saves a new draft; the "Save and post" button posts it straight away. */
    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $purchase = DB::transaction(function () use ($request) {
            $purchase = Purchase::query()->create($this->header($request) + ['created_by' => $request->user()->id]);
            $this->saveLines($purchase, $request);

            return $purchase;
        });

        return $request->boolean('post') ? $this->doPost($request, $purchase, $stock)
            : redirect()->route('dash.inventory.purchases.show', $purchase)->with('ok', __('Purchase saved as a draft.'));
    }

    public function show(Request $request, Purchase $purchase): View
    {
        $this->authorizeBranch($request, $purchase);

        return $this->form($request, $purchase->load(['lines.ingredient', 'supplier', 'branch', 'creator:id,name', 'poster:id,name', 'canceller:id,name']));
    }

    public function update(Request $request, Purchase $purchase, StockService $stock): RedirectResponse
    {
        $this->authorizeBranch($request, $purchase);
        // locked and re-read: a draft posted meanwhile keeps the lines its stock moved by
        $saved = DB::transaction(function () use ($request, $purchase) {
            if (! Purchase::query()->lockForUpdate()->find($purchase->id)?->isDraft()) {
                return false;
            }
            $purchase->update($this->header($request));
            $this->saveLines($purchase, $request);

            return true;
        });
        if (! $saved) {
            return back()->withErrors(['purchase' => __('A posted purchase can no longer change.')]);
        }

        return $request->boolean('post') ? $this->doPost($request, $purchase, $stock) : back()->with('ok', __('Purchase saved as a draft.'));
    }

    public function post(Request $request, Purchase $purchase, StockService $stock): RedirectResponse
    {
        $this->authorizeBranch($request, $purchase);

        return $this->doPost($request, $purchase, $stock);
    }

    /** A manager takes a posted purchase back out of the stock (entered twice, goods returned …), with a reason. */
    public function cancel(Request $request, Purchase $purchase, StockService $stock): RedirectResponse
    {
        $this->authorizeBranch($request, $purchase);
        abort_unless(Permissions::isManager($request->user()), 403, __('Only a manager can cancel a posted purchase.'));
        $data = $request->validate(['reason' => 'required|string|max:200']);
        if (! $stock->cancelPurchase($purchase, $request->user(), $data['reason'])) {
            return back()->withErrors(['reason' => __('Only a posted purchase can be cancelled.')]);
        }
        Audit::record('stock.purchase_cancelled', $this->subject($purchase), [
            'purchase_id' => $purchase->id, 'branch_id' => $purchase->branch_id, 'total' => $purchase->total, 'reason' => $data['reason'],
        ]);

        return back()->with('ok', __('Purchase cancelled and its goods taken out of the stock.'));
    }

    public function destroy(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorizeBranch($request, $purchase);
        $deleted = DB::transaction(fn () => Purchase::query()->lockForUpdate()->find($purchase->id)?->isDraft() && $purchase->delete());
        if (! $deleted) {
            return back()->withErrors(['purchase' => __('A posted purchase can no longer change.')]);
        }

        return redirect()->route('dash.inventory.purchases')->with('ok', __('Draft purchase deleted.'));
    }

    private function doPost(Request $request, Purchase $purchase, StockService $stock): RedirectResponse
    {
        $back = redirect()->route('dash.inventory.purchases.show', $purchase);
        if (! $purchase->lines()->exists()) {
            return $back->withErrors(['lines' => __('Add at least one line with a quantity.')]);
        }
        if (! $stock->postPurchase($purchase, $request->user())) {
            return $back->withErrors(['purchase' => __('A posted purchase can no longer change.')]);
        }
        Audit::record('stock.purchase_posted', $this->subject($purchase), [
            'purchase_id' => $purchase->id, 'branch_id' => $purchase->branch_id, 'supplier_id' => $purchase->supplier_id, 'total' => $purchase->total,
        ]);

        return $back->with('ok', __('Purchase posted: the goods were added to the stock.'));
    }

    private function form(Request $request, Purchase $purchase): View
    {
        return view('dashboard.inventory.purchase', [
            'purchase' => $purchase, 'max' => self::MAX_LINES, 'isManager' => Permissions::isManager($request->user()),
            'suppliers' => Supplier::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $purchase->supplier_id))->orderBy('name')->get(['id', 'name']),
            'branches' => $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get(),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** @return array<string,mixed> the invoice header from the form */
    private function header(Request $request): array
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'purchased_on' => 'required|date|before_or_equal:tomorrow',
            'reference' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:200',
            'lines' => 'array|max:'.self::MAX_LINES,
            'lines.*.ingredient_id' => ['nullable', Rule::exists('ingredients', 'id')->where('tenant_id', $tenant)],
            'lines.*.quantity' => 'nullable|numeric|min:0|max:1000000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0|max:1000000',
        ]);
        $branchId = $request->user()->branch_id ?: ($data['branch_id'] ?? InventoryController::currentBranch($request)->id);

        return ['supplier_id' => (int) $data['supplier_id'], 'branch_id' => (int) $branchId, 'purchased_on' => $data['purchased_on'],
            'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null];
    }

    /** Replaces the lines with the posted rows (rows without an ingredient or a quantity are skipped) and the total. */
    private function saveLines(Purchase $purchase, Request $request): void
    {
        PurchaseLine::query()->where('purchase_id', $purchase->id)->delete();
        $total = 0.0;
        foreach ((array) $request->input('lines', []) as $row) {
            $qty = round((float) ($row['quantity'] ?? 0), 3);
            if (empty($row['ingredient_id']) || $qty <= 0) {
                continue;
            }
            $unitCost = round((float) ($row['unit_cost'] ?? 0), 4);
            $lineTotal = round($qty * $unitCost, 2);
            PurchaseLine::query()->create([
                'purchase_id' => $purchase->id, 'ingredient_id' => (int) $row['ingredient_id'], 'quantity' => $qty, 'unit_cost' => $unitCost, 'total' => $lineTotal,
            ]);
            $total += $lineTotal;
        }
        $purchase->forceFill(['total' => round($total, 2)])->save();
    }

    private function subject(Purchase $purchase): string
    {
        return trim($purchase->supplier?->name.' '.$purchase->reference.' #'.$purchase->id);
    }

    /** Staff tied to one branch only see that branch's purchases. */
    private function authorizeBranch(Request $request, Purchase $purchase): void
    {
        $own = $request->user()->branch_id;
        abort_if($own && (int) $own !== (int) $purchase->branch_id, 404);
    }
}
