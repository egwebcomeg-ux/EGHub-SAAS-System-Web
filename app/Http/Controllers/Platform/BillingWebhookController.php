<?php

namespace App\Http\Controllers\Platform;

use App\Billing\OnlinePayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST app.<domain>/billing/{gateway}/webhook — the gateway (Kashier) tells us how a checkout ended. Server to
 * server: no session, no CSRF, authenticated only by the gateway's signature. The invoice and the restaurant are
 * found from our own payment row (merchant_ref), never from anything else in the body.
 * 403 for an unsigned or tampered call; 200 once a signed event is handled (so the gateway stops retrying),
 * whatever it was about; a crash answers 500 and the gateway delivers it again later.
 */
class BillingWebhookController extends Controller
{
    public function receive(Request $request, string $gateway, OnlinePayments $online): JsonResponse
    {
        abort_unless($online->gateway()->key() === $gateway, 404);
        $result = $online->gateway()->verifyWebhook($request);
        if (! $result) {
            Log::warning('Billing webhook with a bad signature', ['gateway' => $gateway, 'ip' => $request->ip()]);
            abort(403);
        }
        $payment = $online->settle($result);

        return response()->json(['ok' => true, 'handled' => $payment !== null]);
    }
}
