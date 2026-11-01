<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every past order of the restaurant, to find yesterday's order on a complaint. Staff tied to a branch see that branch only. */
class OrderHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value = (string) $request->query($key, '')) ? $value : '';
        $filters = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 60),
            'status' => array_key_exists($status = (string) $request->query('status', ''), Order::STATUSES) ? $status : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
        $q = $filters['q'];
        $phone = $q !== '' ? normalize_phone($q) : null;

        $orders = Order::query()->with(['branch', 'table'])
            ->when($request->user()->branch_id, fn ($w, $branch) => $w->where('branch_id', $branch))
            ->when($q !== '', fn ($w) => $w->where(fn ($m) => $m->where('customer_name', 'like', "%$q%")
                ->orWhere('customer_phone', 'like', "%$q%")
                ->when($phone, fn ($p) => $p->orWhere('customer_phone', $phone))
                ->when(ctype_digit($q), fn ($n) => $n->orWhere('number', (int) $q))))
            ->when($filters['status'] !== '', fn ($w) => $w->where('status', $filters['status']))
            ->when($filters['from'] !== '', fn ($w) => $w->where('created_at', '>=', local_day_start($filters['from'])))
            ->when($filters['to'] !== '', fn ($w) => $w->where('created_at', '<', local_day_start($filters['to'])->addDay()))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('dashboard.orders.history', ['orders' => $orders, 'filters' => $filters]);
    }
}
