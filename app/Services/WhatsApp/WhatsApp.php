<?php

namespace App\Services\WhatsApp;

use App\Billing\Credits;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp messages for the current restaurant. Every message is a whatsapp_messages row first (queued),
 * then goes out from the queue: one credit is taken from the restaurant's wallet; with no credit left the message
 * is not sent (failed, error = no_credits); a provider failure gives the credit back.
 */
final class WhatsApp
{
    public function __construct(private Credits $credits, private WhatsAppGateway $gateway) {}

    /**
     * Records a message and sends it from the queue (after the surrounding transaction commits).
     * Returns null when the phone is not a valid Egyptian mobile. Must run inside a tenant context.
     *
     * @param  array<string,mixed>  $values  template values by name (Templates::PARAMS)
     */
    public function queue(string $phone, string $templateKey, array $values, ?int $customerId = null): ?WhatsAppMessage
    {
        $message = $this->record($phone, $templateKey, $values, $customerId);
        if ($message) {
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Creates the queued row without sending it (campaigns and automations send their batch themselves).
     *
     * @param  array<string,mixed>  $values
     */
    public function record(string $phone, string $templateKey, array $values, ?int $customerId = null, ?int $campaignId = null, ?int $automationId = null): ?WhatsAppMessage
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
        $reference = 'wa:'.$message->id;
        if (! $this->credits->spend($message->tenant_id, $reference)) {
            $message->update(['status' => 'failed', 'error' => WhatsAppMessage::NO_CREDITS]);

            return $message;
        }
        try {
            $id = $this->gateway->send($message->to, $message->template, $message->params ?? [], $message->locale, (string) $message->body);
            $message->update(['status' => 'sent', 'provider_id' => mb_substr($id, 0, 120), 'cost_credits' => 1, 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $this->credits->add($message->tenant_id, 1, 'refund', $reference);
            $error = $e instanceof WhatsAppException ? $e->getMessage() : 'provider error';
            if (! $e instanceof WhatsAppException) {
                Log::error('whatsapp send failed', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
            $message->update(['status' => 'failed', 'error' => mb_substr($error, 0, 255)]);
        }

        return $message;
    }

    /** Guests have no language of their own yet: messages follow the restaurant's default menu language. */
    public static function locale(): string
    {
        return Settings::get('default_locale') === 'en' ? 'en' : 'ar';
    }
}
