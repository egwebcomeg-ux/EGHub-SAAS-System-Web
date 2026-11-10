<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PagerTicket;
use App\Services\Audit;
use App\Services\Notify\GuestNotifier;
use App\Services\Orders\OrderException;
use App\Services\Orders\Pager;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guest pager on the staff side (plan feature pager, permission pager.use):
 *  - the pager station (/pager), a tablet screen for places that take orders on another POS: type the order number
 *    (or take the next one), the guest scans the big QR, and the queue below calls and closes each ticket;
 *  - "Call" on a real order from the order board, the kitchen display and the POS, and its WhatsApp fallback.
 * Staff tied to a branch only reach their branch's tickets and orders.
 */
class PagerController extends Controller
{
    /** Picked-up / expired tickets stay on the queue this long, so a mistaken tap can still be seen. */
    private const RECENT_MINUTES = 30;

    public function station(Request $request): View
    {
        $branch = PosController::branch($request);

        return view('dashboard.pager', [
            'branch' => $branch,
            'next' => Pager::nextNumber($branch),
            'poll' => Pager::POLL_SECONDS,
            'autoCall' => Pager::autoCalls(),
            'expireHours' => Pager::expireHours(),
        ]);
    }

    /** The queue of the branch: open tickets oldest first, then the ones closed in the last half hour. */
    public function feed(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        Pager::expireStale($branch->id);
        $tickets = PagerTicket::query()->where('branch_id', $branch->id)
            ->where(fn ($q) => $q->whereIn('status', ['waiting', 'called'])->orWhere('updated_at', '>=', now()->subMinutes(self::RECENT_MINUTES)))
            ->orderBy('id')->limit(120)->get();

        return response()->json([
            'next' => Pager::nextNumber($branch),
            'tickets' => $tickets->map(fn (PagerTicket $t) => $this->present($t))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $data = $request->validate(['number' => 'nullable|integer|min:1|max:999999']);
        try {
            $ticket = Pager::open($branch, isset($data['number']) ? (int) $data['number'] : null, $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'ticket' => $this->present($ticket), 'qr' => self::qr($ticket), 'next' => Pager::nextNumber($branch)]);
    }

    /** The big QR of a ticket again (the guest asks a second time). */
    public function showQr(Request $request, PagerTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);

        return response()->json(['ticket' => $this->present($ticket), 'qr' => self::qr($ticket)]);
    }

    private static function qr(PagerTicket $ticket): string
    {
        return Qr::svg(tenant()->url('p/'.$ticket->token), 320, '#0A2540');
    }

    public function callTicket(Request $request, PagerTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        try {
            Pager::call($ticket);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'ticket' => $this->present($ticket->fresh())]);
    }

    public function pickedUp(Request $request, PagerTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        Pager::pickedUp($ticket);

        return response()->json(['ok' => true, 'ticket' => $this->present($ticket->fresh())]);
    }

    /** A small 80mm ticket with the number and the QR, for the guest to take away. */
    public function print(Request $request, PagerTicket $ticket): View
    {
        $this->ownTicket($request, $ticket);

        return view('dashboard.pager-ticket', [
            'number' => $ticket->number, 'url' => tenant()->url('p/'.$ticket->token), 'branch' => $ticket->branch,
            'settings' => Settings::all(), 'at' => $ticket->created_at,
        ]);
    }

    /** Auto-call when an order turns ready, and how long a station ticket stays open (settings.manage). */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $data = $request->validate(['auto_call' => 'nullable|boolean', 'expire_hours' => 'required|integer|min:1|max:'.Pager::MAX_EXPIRE_HOURS]);
        Settings::put(['pager_auto_call' => $request->boolean('auto_call') ? '1' : '0', 'pager_expire_hours' => (string) $data['expire_hours']]);
        Audit::record('settings.updated', __('Guest pager'));

        return back()->with('ok', __('Saved.'));
    }

    /** "Call" on an order's card (board, kitchen, POS): the guest's phone rings again. */
    public function callOrder(Request $request, Order $order): JsonResponse
    {
        $this->ownOrder($request, $order);
        try {
            Pager::call($order);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'pager' => self::orderView($order->fresh(), $request)]);
    }

    /** The guest closed the page: "your order is ready" on WhatsApp instead (one credit). */
    public function whatsappOrder(Request $request, Order $order, GuestNotifier $notifier): JsonResponse
    {
        $this->ownOrder($request, $order);
        if (! $notifier->orderReadyCall($order)) {
            return response()->json(['message' => __('This order has no mobile number, or WhatsApp messages are not in your plan.')], 422);
        }

        return response()->json(['ok' => true, 'message' => __('Sent on WhatsApp.')]);
    }

    /**
     * The pager of an order as the staff screens show it, with the actions this user may take; null when the order
     * cannot carry a pager (table or delivery) or the plan has no pager.
     *
     * @return array<string,mixed>|null
     */
    public static function orderView(Order $order, Request $request): ?array
    {
        if (! Pager::enabled() || ! Pager::forOrder($order)) {
            return null;
        }
        $can = $request->user()->can('pager.use');

        return Pager::staffView($order) + [
            'call' => $can ? route('dash.pager.orders.call', $order) : null,
            'whatsapp' => $can && GuestNotifier::canCallOnWhatsApp($order) ? route('dash.pager.orders.whatsapp', $order) : null,
            'print' => $request->user()->canAny(['orders.view', 'pos.use', 'pager.use']) && ! Pager::isDone($order)
                ? route('dash.orders.receipt', ['order' => $order, 'copy' => 'pager']) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function present(PagerTicket $t): array
    {
        return Pager::staffView($t) + [
            'id' => $t->id, 'number' => $t->number, 'status' => $t->status,
            'time' => local_time($t->created_at, 'h:i A'), 'created_at' => $t->created_at->toIso8601String(),
            'url' => tenant()->url('p/'.$t->token),
            'call' => route('dash.pager.tickets.call', $t), 'picked' => route('dash.pager.tickets.picked', $t),
            'print' => route('dash.pager.tickets.print', $t), 'qr_url' => route('dash.pager.tickets.qr', $t),
        ];
    }

    private function ownTicket(Request $request, PagerTicket $ticket): void
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $ticket->branch_id, 404);
    }

    private function ownOrder(Request $request, Order $order): void
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        abort_unless(Pager::forOrder($order), 404);
    }
}
