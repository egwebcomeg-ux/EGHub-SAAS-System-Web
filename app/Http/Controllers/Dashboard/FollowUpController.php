<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\FollowUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «متابعة العملاء»: the day's orders that have a mobile, from every source, each with a WhatsApp Web button that opens
 * the guest's chat with a message for where the order is now (App\Services\Orders\FollowUp). Staff tied to a branch
 * see that branch only.
 */
class FollowUpController extends Controller
{
    /** status filter => the order statuses it shows */
    public const FILTERS = [
        'open' => ['new', 'preparing', 'ready', 'on_way'],
        'served' => ['served'],
        'cancelled' => ['cancelled'],
    ];

    public function index(Request $request): View
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value = (string) $request->query('day', '')) ? $value : local_at()->format('Y-m-d');
        $filters = [
            'day' => $day,
            'status' => array_key_exists($status = (string) $request->query('status', ''), self::FILTERS) ? $status : '',
            'source' => array_key_exists($source = (string) $request->query('source', ''), Order::SOURCES) ? $source : '',
            'pending' => $request->boolean('pending'),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 60),
        ];
        $q = $filters['q'];

        $orders = Order::query()->with(['branch', 'followedUpBy'])
            ->where('customer_phone', '!=', '')
            ->where('created_at', '>=', local_day_start($day))->where('created_at', '<', local_day_start($day)->addDay())
            ->when($request->user()->branch_id, fn ($w, $branch) => $w->where('branch_id', $branch))
            ->when($filters['status'] !== '', fn ($w) => $w->whereIn('status', self::FILTERS[$filters['status']]))
            ->when($filters['source'] !== '', fn ($w) => $w->where('source', $filters['source']))
            ->when($filters['pending'], fn ($w) => $w->whereNull('followed_up_at'))
            ->when($q !== '', fn ($w) => $w->where(fn ($m) => $m->where('customer_name', 'like', "%$q%")
                ->orWhere('customer_phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')
                ->when(ctype_digit($q), fn ($n) => $n->orWhere('number', (int) $q))))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('dashboard.follow-up', ['orders' => $orders, 'filters' => $filters]);
    }

    /** Opening the chat marks the order (the page calls this as the WhatsApp Web tab opens). */
    public function mark(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        FollowUp::mark($order, $request->user());
        $order->load('followedUpBy');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'by' => $order->followedUpBy?->name, 'at' => local_time($order->followed_up_at, 'h:i A')])
            : back()->with('ok', __('Marked as followed up.'));
    }
}
