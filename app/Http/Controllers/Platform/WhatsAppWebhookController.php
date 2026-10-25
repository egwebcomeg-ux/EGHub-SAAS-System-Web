<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderConfirmation;
use App\Services\WhatsApp\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WhatsApp on the main domain. Three callers:
 *  - Meta Cloud API for the EgHub number (/webhooks/whatsapp): GET for the one-time subscription handshake, POST
 *    with every payload signed by EgHub's app secret (X-Hub-Signature-256). Texts feed the phone confirmation;
 *    button taps answer an order confirmation (App\Services\Orders\OrderConfirmation); message statuses update
 *    whatsapp_messages. A change whose metadata.phone_number_id is a restaurant's connected number (our own app
 *    subscribed to its WABA, as Embedded Signup will do) is handled for that restaurant only.
 *  - Meta Cloud API for a restaurant's own number (/webhooks/whatsapp/{webhook_key}): the restaurant's own Meta app
 *    calls the callback URL and verify token shown on its dashboard, and signs with ITS app secret (stored encrypted
 *    on App\Models\WhatsAppConnection), so the signature is checked against that secret. Only changes for that
 *    restaurant's phone_number_id are handled, never another restaurant's.
 *  - another provider (the webhook driver's counterpart): POST {from, text} or {from, payload} with the shared
 *    X-EgMenu-Secret.
 * Meta always gets 200 for a valid payload, whatever it contains; only an order confirmation tap gets a reply, sent
 * from the number the guest tapped on.
 */
class WhatsAppWebhookController extends Controller
{
    /** Delivery states in order; a status only ever moves forward (a late "delivered" never undoes "read"). */
    private const RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    public function verify(Request $request): Response
    {
        return $this->handshake($request, (string) config('whatsapp.verify_token'));
    }

    /** The handshake of a restaurant's own Meta app (its verify token is on its dashboard). */
    public function verifyOwn(Request $request, string $key): Response
    {
        $connection = $this->connection($key);
        $response = $this->handshake($request, (string) $connection->verify_token);
        $connection->forceFill(['webhook_verified_at' => now()])->save();

        return $response;
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

        $this->assertMetaSignature($request, (string) config('whatsapp.app_secret'), true);
        $this->handle($request, $confirmation, $verification, null);

        return response('', 200);
    }

    /** A payload from a restaurant's own Meta app, signed with that app's secret. */
    public function receiveOwn(Request $request, string $key, OrderConfirmation $confirmation): Response
    {
        $connection = $this->connection($key);
        $this->assertMetaSignature($request, (string) $connection->app_secret, false);
        $this->handle($request, $confirmation, null, $connection);

        return response('', 200);
    }

    /**
     * Every change of the payload. $fixed = the restaurant whose own URL was called: changes for any other number are
     * skipped. On the EgHub URL a change is routed by metadata.phone_number_id (EgHub's number or unknown: as always).
     */
    private function handle(Request $request, OrderConfirmation $confirmation, ?PhoneVerification $verification, ?WhatsAppConnection $fixed): void
    {
        $touched = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($fixed) {
                    if ($phoneId !== $fixed->phone_number_id) {
                        continue; // never another restaurant's number through this restaurant's URL
                    }
                    $connection = $fixed;
                } else {
                    $connection = $this->connectionFor($phoneId);
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    // the account phone confirmation is a message to the EgHub number only
                    if (! $connection && $verification && ($message['type'] ?? '') === 'text' && isset($message['from'], $message['text']['body'])) {
                        $verification->confirm((string) $message['from'], (string) $message['text']['body']);
                    }
                    // a tap on a template's quick-reply button (type button) or on an interactive reply button
                    $payload = $message['button']['payload'] ?? $message['interactive']['button_reply']['id'] ?? null;
                    // (Meta resends a payload it thinks was not received: the same wamid is answered once)
                    if (isset($message['from']) && is_string($payload) && $payload !== ''
                        && Cache::add('whatsapp:tap:'.($message['id'] ?? sha1($payload.$message['from'])), 1, now()->addDay())) {
                        $confirmation->handleTap((string) $message['from'], $payload, $connection);
                    }
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->recordStatus((array) $status, $connection);
                }
                if ($connection) {
                    $touched[$connection->id] = $connection;
                }
            }
        }
        foreach ($touched as $connection) {
            $connection->forceFill(['last_inbound_at' => now()])->save();
        }
    }

    /** A restaurant's connected number other than EgHub's own (null: EgHub's number, or no number given). */
    private function connectionFor(string $phoneId): ?WhatsAppConnection
    {
        if ($phoneId === '' || $phoneId === (string) config('whatsapp.meta.phone_id')) {
            return null;
        }

        return WhatsAppConnection::query()->withoutGlobalScopes()->where('phone_number_id', $phoneId)->where('status', 'connected')->first();
    }

    private function connection(string $key): WhatsAppConnection
    {
        return WhatsAppConnection::query()->withoutGlobalScopes()->where('webhook_key', $key)->firstOrFail();
    }

    private function handshake(Request $request, string $token): Response
    {
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode') ?? '');
        $given = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? '');
        abort_unless($token !== '' && $mode === 'subscribe' && hash_equals($token, $given), 403);

        return response((string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    /** Another provider calling us with the shared secret of the webhook driver (header X-EgMenu-Secret). */
    private function fromProvider(Request $request): bool
    {
        $secret = (string) config('whatsapp.webhook.secret');
        $given = (string) $request->header('X-EgMenu-Secret', '');

        return $secret !== '' && $given !== '' && hash_equals($secret, $given);
    }

    /** Meta signs the raw body with the app's secret; without that secret every payload is refused. */
    private function assertMetaSignature(Request $request, string $secret, bool $platform): void
    {
        if ($secret === '') {
            if ($platform && Cache::add('whatsapp:webhook:unconfigured', 1, now()->addDay())) {
                Log::warning('WhatsApp webhook refused: WHATSAPP_APP_SECRET is not set, so Meta payloads cannot be verified.');
            }
            abort(403);
        }
        $given = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless($given !== '' && hash_equals($expected, $given), 403);
    }

    /**
     * @param  array<string,mixed>  $status  one entry of value.statuses (id, status, errors)
     * @param  ?WhatsAppConnection  $connection  a restaurant's own number: only that restaurant's messages are looked at
     */
    private function recordStatus(array $status, ?WhatsAppConnection $connection): void
    {
        $id = (string) ($status['id'] ?? '');
        $state = strtolower((string) ($status['status'] ?? ''));
        if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $message = WhatsAppMessage::query()->withoutGlobalScopes()->where('provider_id', $id)
            ->when($connection, fn ($q) => $q->where('tenant_id', $connection->tenant_id))->first();
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
