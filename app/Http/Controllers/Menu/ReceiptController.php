<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Settings;
use Illuminate\View\View;

/**
 * /r/{order}/{signature} on the restaurant's own address: the read-only receipt the guest opens from the QR on the
 * printed receipt or the WhatsApp link. Only with the right signature (Order::receiptSignature), only orders of this
 * restaurant, and nothing personal: no name, phone or address, just what was bought and paid.
 */
class ReceiptController extends Controller
{
    public function show(int $order, string $signature): View
    {
        $found = Order::query()->with(['items', 'branch', 'refunds'])->find($order);
        abort_unless($found && hash_equals($found->receiptSignature(), $signature), 404);

        return view('menu.receipt', ['order' => $found, 'settings' => Settings::all()]);
    }
}
