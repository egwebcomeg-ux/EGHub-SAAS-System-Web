<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderConfirmation;
use App\Services\WhatsApp\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WhatsApp on the main domain (/webhooks/whatsapp). Two callers:
 *  - Meta Cloud API: GET for the one-time subscription handshake, POST with every payload signed by the app
 *    secret (X-Hub-Signature-256). Texts feed the phone confirmation; button taps answer an order confirmation
 *    (App\Services\Orders\OrderConfirmation); message statuses update whatsapp_messages.
 *  - another provider (the webhook driver's counterpart): POST {from, text} or {from, payload} with the shared
 *    X-EgMenu-Secret.
 * Meta always gets 200 for a valid payload, whatever it contains; only an order confirmation tap gets a reply.
 */
class WhatsAppWebhookController extends Controller
{
    /** Delivery states in order; a status only ever moves forward (a late "delivered" never undoes "read"). */
    private const RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token');
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode') ?? '');
        $given = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? '');
        abort_unless($token !== '' && $mode === 'subscribe' && hash_equals($token, $given), 403);

        return response((string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, PhoneVerification $verification, OrderConfirmation $confirmation): Response|JsonResponse
    {
        if ($this->fromProvider($request)) {
            $from = (string) $request->input('from', '');
            $text = (string) $request->input('text', '');
            $payload = (string) $request->input('payload', '');
            $user = $from !== '' && $text !== '' ? $verification->confirm($from, $text) : null;
            $order = $from !== '' && $payload !== '' ? $confirmation->handleTap($from, $payload) : null;

            return response()->json(['ok' => true, 'verified' => $user !== null, 'answered' => $order !== null]);
        }

        $this->assertMetaSignature($request);
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (($message['type'] ?? '') === 'text' && isset($message['from'], $message['text']['body'])) {
                        $verification->confirm((string) $message['from'], (string) $message['text']['body']);
                    }
                    // a tap on a template's quick-reply button (type button) or on an interactive reply button
                    $payload = $message['button']['payload'] ?? $message['interactive']['button_reply']['id'] ?? null;
                    // (Meta resends a payload it thinks was not received: the same wamid is answered once)
                    if (isset($message['from']) && is_string($payload) && $payload !== ''
                        && Cache::add('whatsapp:tap:'.($message['id'] ?? sha1($payload.$message['from'])), 1, now()->addDay())) {
                        $confirmation->handleTap((string) $message['from'], $payload);
                    }
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->recordStatus($status);
                }
            }
        }

        return response('', 200);
    }

    /** Another provider calling us with the shared secret of the webhook driver (header X-EgMenu-Secret). */
    private function fromProvider(Request $request): bool
    {
        $secret = (string) config('whatsapp.webhook.secret');
        $given = (string) $request->header('X-EgMenu-Secret', '');

        return $secret !== '' && $given !== '' && hash_equals($secret, $given);
    }

    /** Meta signs the raw body with the app secret; without that secret in .env every payload is refused. */
    private function assertMetaSignature(Request $request): void
    {
        $secret = (string) config('whatsapp.app_secret');
        if ($secret === '') {
            if (Cache::add('whatsapp:webhook:unconfigured', 1, now()->addDay())) {
                Log::warning('WhatsApp webhook refused: WHATSAPP_APP_SECRET is not set, so Meta payloads cannot be verified.');
            }
            abort(403);
        }
        $given = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless($given !== '' && hash_equals($expected, $given), 403);
    }

    /** @param  array<string,mixed>  $status  one entry of value.statuses (id, status, errors) */
    private function recordStatus(array $status): void
    {
        $id = (string) ($status['id'] ?? '');
        $state = strtolower((string) ($status['status'] ?? ''));
        if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $message = WhatsAppMessage::query()->withoutGlobalScopes()->where('provider_id', $id)->first();
        if (! $message) {
            return;
        }
        if ($state === 'failed') {
            $error = (string) ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'provider failed');
            $message->update(['status' => 'failed', 'error' => mb_substr($error, 0, 255)]);
            OrderConfirmation::messageFailed($message);

            return;
        }
        if ((self::RANK[$message->status] ?? 0) < self::RANK[$state]) {
            $message->update(['status' => $state]);
        }
    }
}
