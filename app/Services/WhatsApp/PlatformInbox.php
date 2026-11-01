<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use Illuminate\Support\Facades\DB;

/**
 * «محادثات واتساب»: keeps what people write to the EgHub number and sends the team's answers from it. A sign-in or
 * phone-confirmation code and a button tap never come here (the webhook handles those). Meta resends a payload it
 * thinks was not received, so a message is kept once per wamid. Answers are free-form text, so they go out only
 * inside the 24-hour window the person opened (PlatformConversation::canReply).
 */
final class PlatformInbox
{
    /** The text of the approved template egmenu_welcome, as the team reads it in the conversation. */
    public const WELCOME_TEMPLATE = 'أهلًا {{1}}، معاك فريق EgHub للمنيو الإلكتروني والطلبات للمطاعم والكافيهات. لو حابب تعرف الأسعار أو تبدأ، رد على الرسالة دي وهنساعدك.';

    /** The label of what cannot be shown as text: media stays on the sender's and on Meta's side. */
    private const TYPE_LABELS = [
        'image' => 'صورة', 'audio' => 'رسالة صوتية', 'video' => 'فيديو', 'document' => 'ملف', 'sticker' => 'ملصق',
        'location' => 'موقع', 'contacts' => 'جهة اتصال',
    ];

    /**
     * One message of value.messages. Reopens a closed conversation.
     *
     * @param  array<string, mixed>  $message
     * @param  array<int, array<string, mixed>>  $contacts  value.contacts (the profile names)
     */
    public function receive(array $message, array $contacts = []): ?PlatformConversationMessage
    {
        $phone = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?? '';
        $providerId = (string) ($message['id'] ?? '');
        if ($phone === '' || strlen($phone) > 20 || $providerId === '') {
            return null;
        }
        if (PlatformConversationMessage::query()->where('provider_id', $providerId)->exists()) {
            return null;
        }
        $type = mb_substr((string) ($message['type'] ?? 'text'), 0, 20);
        $body = self::text($message, $type);
        $name = $this->profileName($contacts, $phone);

        return DB::transaction(function () use ($phone, $name, $providerId, $type, $body) {
            $conversation = PlatformConversation::query()->firstOrCreate(['phone' => $phone]);
            $row = $conversation->messages()->create(['direction' => 'in', 'type' => $type, 'body' => $body, 'provider_id' => $providerId]);
            $conversation->forceFill([
                'name' => $name ?? $conversation->name, 'status' => 'open', 'unread' => $conversation->unread + 1,
                'last_message_at' => now(), 'last_inbound_at' => now(),
            ])->save();

            return $row;
        });
    }

    /**
     * An answer from the team, or from App\Services\WhatsApp\PlatformAutoReply when $admin is null. Returns the row, with
     * status failed and the reason when Meta refused it. A person from the team answering quiets the automatic answer.
     */
    public function reply(PlatformConversation $conversation, ?PlatformAdmin $admin, string $text): PlatformConversationMessage
    {
        $row = $this->deliver($conversation, $admin, $text, fn (MetaCloudDriver $driver) => $driver->reply($conversation->phone, $text));
        if ($admin) {
            $conversation->forceFill(['bot_paused_until' => now()->addHours(PlatformAutoReply::PAUSE_HOURS)])->save();
        }

        return $row;
    }

    /**
     * The automatic welcome with its reply buttons.
     *
     * @param  array<string, string>  $buttons  id => title
     */
    public function replyWithButtons(PlatformConversation $conversation, string $text, array $buttons): PlatformConversationMessage
    {
        return $this->deliver($conversation, null, $text, fn (MetaCloudDriver $driver) => $driver->replyWithButtons($conversation->phone, $text, $buttons));
    }

    /**
     * The team writes first to a new customer: only an approved template may open a conversation. The person's answer
     * opens the 24-hour window, and the automatic answer takes it from there.
     */
    public function start(string $phone, string $name, PlatformAdmin $admin): PlatformConversationMessage
    {
        $conversation = PlatformConversation::query()->firstOrCreate(['phone' => $phone], ['name' => $name]);
        $text = str_replace('{{1}}', $name, self::WELCOME_TEMPLATE);

        return $this->deliver($conversation, $admin, $text, fn (MetaCloudDriver $driver) => $driver->send($phone, 'welcome', [$name]));
    }

    /** @param  \Closure(MetaCloudDriver): string  $send */
    private function deliver(PlatformConversation $conversation, ?PlatformAdmin $admin, string $text, \Closure $send): PlatformConversationMessage
    {
        $row = $conversation->messages()->create(['direction' => 'out', 'body' => $text, 'status' => 'sending', 'admin_id' => $admin?->id]);
        try {
            $id = $send(new MetaCloudDriver);
            $row->update(['status' => 'sent', 'provider_id' => mb_substr($id, 0, 120)]);
        } catch (WhatsAppException $e) {
            $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)]);
        }
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $row;
    }

    /** Delivery news for an answer of the team; true when the message was one of ours. */
    public function recordStatus(string $providerId, string $state, ?string $error): bool
    {
        $row = PlatformConversationMessage::query()->where('provider_id', $providerId)->where('direction', 'out')->first();
        if (! $row) {
            return false;
        }
        $rank = ['sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        if ($state === 'failed') {
            $row->update(['status' => 'failed', 'error' => mb_substr((string) $error, 0, 255)]);
        } elseif (($rank[$row->status] ?? -1) < ($rank[$state] ?? -1)) {
            $row->update(['status' => $state]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $message */
    private static function text(array $message, string $type): string
    {
        $text = match ($type) {
            'text' => (string) ($message['text']['body'] ?? ''),
            'image', 'video', 'document' => (string) ($message[$type]['caption'] ?? ''),
            'interactive' => (string) ($message['interactive']['button_reply']['title'] ?? ''),
            default => '',
        };
        $label = self::TYPE_LABELS[$type] ?? (in_array($type, ['text', 'interactive'], true) ? '' : $type);

        return trim(($label !== '' ? "[{$label}] " : '').$text);
    }

    /** @param  array<int, array<string, mixed>>  $contacts */
    private function profileName(array $contacts, string $phone): ?string
    {
        foreach ($contacts as $contact) {
            if ((string) ($contact['wa_id'] ?? '') === $phone && filled($contact['profile']['name'] ?? null)) {
                return mb_substr((string) $contact['profile']['name'], 0, 120);
            }
        }

        return null;
    }
}
