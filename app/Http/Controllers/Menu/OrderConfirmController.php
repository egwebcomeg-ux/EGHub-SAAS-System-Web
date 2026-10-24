<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderConfirmation;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * /oc/{token}/{signature} on the restaurant's own address: the guest's confirmation page of a cash-on-delivery
 * order, linked from the WhatsApp message when the provider cannot show buttons. The same three answers as the
 * buttons (App\Services\Orders\OrderConfirmation). Only with the right signature (Order::confirmSignature) and only
 * orders of this restaurant.
 */
class OrderConfirmController extends Controller
{
    public function show(Request $request, string $token, string $signature): View
    {
        $order = $this->find($token, $signature);

        return view('menu.order-confirm', [
            'order' => $order, 'settings' => Settings::all(), 'state' => OrderConfirmation::state($order),
            'answer' => $request->session()->get('order_confirm_answer'),
        ]);
    }

    public function answer(Request $request, string $token, string $signature, OrderConfirmation $confirmation): RedirectResponse
    {
        $order = $this->find($token, $signature);
        $action = $request->validate(['action' => ['required', Rule::in(array_keys(OrderConfirmation::ACTIONS))]])['action'];
        $result = $confirmation->answer($order, $action, 'link');

        return redirect()->to($request->url())->with('order_confirm_answer', $confirmation->replyText($order->fresh('branch'), $action, $result));
    }

    private function find(string $token, string $signature): Order
    {
        $order = Order::query()->with(['items', 'branch'])->where('confirm_token', $token)->first();
        abort_unless($order && hash_equals($order->confirmSignature(), $signature), 404);

        return $order;
    }
}
