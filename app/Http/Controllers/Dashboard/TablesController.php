<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Services\Entitlements;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tables of each branch and their QR codes (one unguessable link per table, printable as cards). */
class TablesController extends Controller
{
    public function index(): View
    {
        $rights = Entitlements::current();
        return view('dashboard.tables.index', [
            'branches' => Branch::query()->orderBy('sort_order')->with(['tables' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->get(),
            'usage'    => ['used' => $rights->usage('tables'), 'limit' => $rights->limit('tables')],
        ]);
    }

    /** Adds tables "from".."to" (numbers) or one named table. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'from'      => 'nullable|integer|min:1|max:999',
            'to'        => 'nullable|integer|min:1|max:999|gte:from',
            'name'      => 'nullable|string|max:40',
        ]);
        $names = $data['name'] ?? null
            ? [$data['name']]
            : array_map('strval', range((int) ($data['from'] ?? 1), (int) ($data['to'] ?? $data['from'] ?? 1)));

        $existing = DiningTable::query()->where('branch_id', $data['branch_id'])->pluck('name')->all();
        $names = array_values(array_diff($names, $existing));
        if ($names === []) {
            return back()->withErrors(['name' => __('These tables already exist.')]);
        }
        $rights = Entitlements::current();
        if (!$rights->canAdd('tables', count($names))) {
            return back()->withErrors(['limit' => $rights->upgradeMessage('tables')]);
        }
        $order = (int) DiningTable::query()->where('branch_id', $data['branch_id'])->max('sort_order');
        foreach ($names as $name) {
            DiningTable::query()->create(['branch_id' => $data['branch_id'], 'name' => $name, 'sort_order' => ++$order]);
        }
        return back()->with('ok', __(':n tables added.', ['n' => count($names)]));
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:40', 'seats' => 'nullable|integer|min:1|max:50']);
        $table->update($data + ['is_active' => $request->boolean('is_active')]);
        return back()->with('ok', __('Table saved.'));
    }

    /** New QR link for a table (old printed QR stops working), e.g. after a photo of it was shared. */
    public function rotate(DiningTable $table): RedirectResponse
    {
        $table->update(['qr_token' => random_token(10)]);
        return back()->with('ok', __('New QR created for table :name. Print it again.', ['name' => $table->name]));
    }

    public function destroy(DiningTable $table): RedirectResponse
    {
        $table->delete();
        return back()->with('ok', __('Table deleted.'));
    }

    /** Printable A4 sheet of QR cards: all tables of a branch, or one table. */
    public function print(Request $request): View
    {
        $query = DiningTable::query()->where('is_active', true)->with('branch')->orderBy('branch_id')->orderBy('sort_order');
        if ($request->filled('table')) {
            $query->whereKey((int) $request->query('table'));
        } elseif ($request->filled('branch')) {
            $query->where('branch_id', (int) $request->query('branch'));
        }
        $color = Settings::get('color_secondary');

        return view('dashboard.tables.print', [
            'cards' => $query->get()->map(fn (DiningTable $t) => [
                'table' => $t, 'url' => $t->menuUrl(), 'qr' => Qr::svg($t->menuUrl(), 300, $color),
            ]),
            'menuQr' => Qr::svg(tenant()->url(), 300, $color),
            'settings' => Settings::all(),
            'onlyMenu' => $request->boolean('menu'),
        ]);
    }
}
