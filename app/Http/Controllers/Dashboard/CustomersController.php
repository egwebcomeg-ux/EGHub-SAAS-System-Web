<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The restaurant's own guest list: everyone who left a phone with an order or joined the loyalty card. */
class CustomersController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['visits_count', 'total_spent', 'last_visit_at'], true) ? $request->query('sort') : 'last_visit_at';
        $customers = $this->search($q)->orderByDesc($sort)->orderByDesc('id')->paginate(30)->withQueryString();

        return view('dashboard.customers.index', [
            'customers' => $customers, 'q' => $q, 'sort' => $sort,
            'stats' => [
                'total' => Customer::query()->count(),
                'returning' => Customer::query()->where('visits_count', '>', 1)->count(),
                'new_month' => Customer::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function show(Customer $customer): View
    {
        return view('dashboard.customers.show', [
            'customer' => $customer->load(['loyaltyCards.program', 'loyaltyCards.availableRewards']),
            'orders' => Order::query()->where('customer_id', $customer->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100', 'phone_2' => 'nullable|string|max:20', 'birthday' => 'nullable|date|before:today',
            'notes' => 'nullable|string|max:1000',
        ]);
        $customer->update($data + ['marketing_opt_in' => $request->boolean('marketing_opt_in')]);
        return back()->with('ok', __('Saved.'));
    }

    /** CSV that opens correctly in Excel (UTF-8 BOM) — the restaurant owns its guest list. */
    public function export(Request $request): StreamedResponse
    {
        // The file is written after the request handling returns, when the tenant scope may no longer be active:
        // pin the restaurant explicitly instead of relying on the global scope.
        $rows = $this->search(trim((string) $request->query('q', '')))
            ->withoutGlobalScopes()->where('tenant_id', tenant()->getTenantKey())->orderBy('id');
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Name'), __('Mobile'), __('Visits'), __('Total spent'), __('Last visit'), __('Birthday'), __('Accepts offers')]);
            $rows->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $c) {
                    fputcsv($out, [$c->name, $c->phone, $c->visits_count, $c->total_spent, $c->last_visit_at?->format('Y-m-d'), $c->birthday?->format('Y-m-d'), $c->marketing_opt_in ? 1 : 0]);
                }
            });
            fclose($out);
        }, 'customers-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function search(string $q)
    {
        return Customer::query()->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%$q%")
            ->orWhere('phone', 'like', '%' . (normalize_phone($q) ?? $q) . '%')));
    }
}
