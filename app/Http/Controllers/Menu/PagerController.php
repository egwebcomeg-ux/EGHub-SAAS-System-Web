<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PagerTicket;
use App\Services\Entitlements;
use App\Services\Orders\Pager;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /p/{key} on the restaurant's own address: the guest pager (plan feature pager). The guest opens it from the QR on
 * the receipt or the pager-station ticket, keeps it open, and it rings when staff call. No login and nothing
 * personal on it: the number and where the order stands. The key is an order's signed pager key or a ticket's
 * token, looked up in this restaurant only (anything else is a 404).
 */
class PagerController extends Controller
{
    /** Route pattern of a pager key: Order::pagerKey() or a PagerTicket token. */
    public const KEY = '[0-9]{1,12}-[a-f0-9]{24}|[a-f0-9]{24}';

    public function show(string $key): View
    {
        $pager = $this->pager($key);
        Pager::seen($pager);

        return view('menu.pager', [
            'key' => $key,
            'state' => Pager::state($pager),
            'settings' => Settings::all(),
            'branch' => $pager->branch,
            'rating' => Entitlements::current()->allows('feedback'),
            // an order placed from the guest's phone lands here: its full details stay one tap away
            'trackingUrl' => $pager instanceof Order ? route('menu.order.show', $pager->tracking_token) : null,
        ]);
    }

    /** Polled every few seconds by the open page; also what tells the staff screens "the guest has the page open". */
    public function state(string $key): JsonResponse
    {
        $pager = $this->pager($key);
        Pager::seen($pager);

        return response()->json(Pager::state($pager))->header('Cache-Control', 'no-store');
    }

    /** "I picked it up": no more calls; the page offers the rating. */
    public function pickedUp(string $key): JsonResponse
    {
        $pager = $this->pager($key);
        Pager::pickedUp($pager);

        return response()->json(Pager::state($pager->fresh()));
    }

    /**
     * The one-tap rating after the pickup (plan feature feedback). 4-5 stars answer with the restaurant's Google
     * review link; 1-3 go to the restaurant only, with the guest's words.
     */
    public function rate(Request $request, string $key): JsonResponse
    {
        abort_unless(Entitlements::current()->allows('feedback'), 404);
        $pager = $this->pager($key);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:1000']);
        if (! Pager::state($pager)['done']) {
            return response()->json(['message' => __('Rate us once you have your order.')], 422);
        }

        return FeedbackController::record((int) $data['rating'], $data['comment'] ?? null, [
            'branch_id' => $pager->branch_id, 'order_id' => $pager instanceof Order ? $pager->id : null,
            'customer_id' => $pager instanceof Order ? $pager->customer_id : null, 'table_name' => null,
        ]);
    }

    private function pager(string $key): Order|PagerTicket
    {
        abort_unless(Pager::enabled(), 404);

        return Pager::find($key) ?? abort(404);
    }
}
