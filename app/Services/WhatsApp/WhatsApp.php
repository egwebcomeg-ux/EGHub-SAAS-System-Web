<?php

namespace App\Services\WhatsApp;

use App\Billing\Credits;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderConfirmation;
use App\Services\Settings;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp messages for the current restaurant. Every message is a whatsapp_messages row first (queued),
 * then goes out from the queue: one credit is taken from the restaurant's wallet; with no credit left the message
 * is not sent (failed, error = no_credits); a provider failure gives the credit back. A free-form reply inside the
 * 24-hour window the guest opened (Templates::SESSION) is recorded the same way but costs no credit.
 *
 * Which number sends it (whatsapp_messages.channel): the restaurant's own WhatsApp Business number when it is
 * connected and the template is approved in its own account (App\Services\WhatsApp\OwnNumber), else the EgHub
 * number as always. Meta bills messages from the restaurant's number to the restaurant's own WABA, so they take no
 * EgHub credit. A reply goes out from the number the guest wrote to (channel set by the inbound webhook).
 */
final class WhatsApp
{
    public function __construct(private Credits $credits, private WhatsAppGateway $gateway) {}

    /**
     * Records a message and sends it from the queue (after the surrounding transaction commits).
     * Returns null when the phone is not a valid Egyptian mobile. Must run inside a tenant context.
     *
     * @param  array<string,mixed>  $values  template values by name (Templates::PARAMS)
     * @param  array<string,string>  $payloads  quick-reply button payloads by action (Templates::BUTTONS)
     * @param  ?string  $link  what a provider without buttons sends instead of them (Templates::linkLine)
     */
    public function queue(string $phone, string $templateKey, array $values, ?int $customerId = null, array $payloads = [], ?string $link = null): ?WhatsAppMessage
    {
        $message = $this->record($phone, $templateKey, $values, $customerId, null, null, $payloads, $link);
        if ($message) {
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Free-form text answering a guest who just wrote or tapped a button (their 24-hour window is open): no template
     * and no credit. Must run inside a tenant context.
     */
    public function reply(string $phone, string $text, ?int $customerId = null, ?string $channel = null): ?WhatsAppMessage
    {
        $message = $this->record($phone, 'session_reply', ['text' => $text], $customerId, channel: $channel);
        if ($message) {
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Creates the queued row without sending it (campaigns and automations send their batch themselves).
     *
     * @param  array<string,mixed>  $values
     * @param  array<string,string>  $payloads
     * @param  ?string  $channel  own | platform to force the sending number (replies); null = decided when sent
     */
    public function record(string $phone, string $templateKey, array $values, ?int $customerId = null, ?int $campaignId = null, ?int $automationId = null, array $payloads = [], ?string $link = null, ?string $channel = null): ?WhatsAppMessage
    {
        $local = normalize_phone($phone);
        if (! $local) {
            return null;
        }
        $locale = self::locale();
        $params = Templates::withDefaults(Templates::params($templateKey, $values), $locale);

        return WhatsAppMessage::query()->create([
            'customer_id' => $customerId, 'campaign_id' => $campaignId, 'automation_id' => $automationId, 'to' => whatsapp_number($local),
            'template' => $templateKey, 'locale' => $locale, 'params' => $params,
            'body' => Templates::render($templateKey, $params, $locale), 'status' => 'queued',
            'buttons' => Templates::buttons($templateKey, $payloads, $locale) ?: null, 'link' => $link, 'channel' => $channel,
        ]);
    }

    /** Sends a queued message now: takes the credit, calls the provider, records the outcome. */
    public function deliver(WhatsAppMessage $message): WhatsAppMessage
    {
        if ($message->status !== 'queued') {
            return $message;
        }
        // claimed atomically: a job delivered twice (or a campaign batch racing it) never sends nor charges twice
        $claimed = WhatsAppMessage::query()->whereKey($message->id)->where('status', 'queued')->update(['status' => 'sending']);
        if ($claimed !== 1) {
            return $message->refresh();
        }
        $message->status = 'sending';
        $session = in_array($message->template, Templates::SESSION, true);
        $own = $this->ownSender($message, $session);
        $message->channel = $own ? 'own' : 'platform';
        $free = $session || $own !== null;
        $reference = 'wa:'.$message->id;
        if (! $free && ! $this->credits->spend($message->tenant_id, $reference)) {
            $message->update(['status' => 'failed', 'error' => WhatsAppMessage::NO_CREDITS]);
            OrderConfirmation::messageFailed($message);

            return $message;
        }
        try {
            $id = $this->send($message, $own);
            $message->update(['status' => 'sent', 'provider_id' => mb_substr($id, 0, 120), 'cost_credits' => $free ? 0 : 1, 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            if (! $free) {
                $this->credits->add($message->tenant_id, 1, 'refund', $reference);
            }
            // the restaurant's token stopped working: its next messages go out from the EgHub number until it reconnects
            if ($own && $e instanceof WhatsAppException && in_array($e->getCode(), OwnNumber::TOKEN_ERRORS, true)) {
                $own->forceFill(['status' => 'failed', 'checked_at' => now(),
                    'last_error' => __('The access token is invalid or expired. Generate a new one with the expiry set to “Never”.')])->save();
            }
            $error = $e instanceof WhatsAppException ? $e->getMessage() : 'provider error';
            if (! $e instanceof WhatsAppException) {
                Log::error('whatsapp send failed', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
            $message->update(['status' => 'failed', 'error' => mb_substr($error, 0, 255)]);
            OrderConfirmation::messageFailed($message);
        }

        return $message;
    }

    /**
     * Hands the message to the provider: buttons when the provider shows them, else the text with the link that
     * replaces them (and the row's body is what the guest really got); free text for a session reply.
     */
    private function send(WhatsAppMessage $message, ?WhatsAppConnection $own = null): string
    {
        $gateway = $own ? new MetaCloudDriver($own->phone_number_id, (string) $own->access_token) : $this->gateway;
        $interactive = $gateway instanceof InteractiveGateway;
        if (in_array($message->template, Templates::SESSION, true) && $interactive) {
            return $gateway->reply($message->to, (string) $message->body);
        }
        $buttons = (array) ($message->buttons ?? []);
        if ($buttons !== [] && $interactive) {
            return $gateway->sendWithButtons($message->to, $message->template, $message->params ?? [], $buttons, $message->locale, (string) $message->body);
        }
        if ($message->link) {
            $message->body = trim((string) $message->body)."\n".Templates::linkLine($message->link, $message->locale);
            $message->save();
        }

        return $gateway->send($message->to, $message->template, $message->params ?? [], $message->locale, (string) $message->body);
    }

    /**
     * The restaurant's own number when it sends this message: a reply only when the guest wrote to that number
     * (channel own); a template when it is approved in the restaurant's account and nothing forced the EgHub number.
     */
    private function ownSender(WhatsAppMessage $message, bool $session): ?WhatsAppConnection
    {
        if ($message->channel === 'platform') {
            return null;
        }
        if ($session) {
            $connection = $message->channel === 'own' ? OwnNumber::current() : null;

            return $connection?->isConnected() ? $connection : null;
        }

        return OwnNumber::sender($message->template, $message->locale);
    }

    /** Guests have no language of their own yet: messages follow the restaurant's default menu language. */
    public static function locale(): string
    {
        return Settings::get('default_locale') === 'en' ? 'en' : 'ar';
    }
}
