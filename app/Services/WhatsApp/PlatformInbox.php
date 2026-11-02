<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Notifications\InboxNeedsAnswer;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use App\Services\WhatsApp\Drivers\MetaPageDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * «المحادثات»: keeps what people write to the EgHub number, or to its page on Messenger and Instagram, and sends the
 * team's answers from where they wrote. A sign-in or
 * phone-confirmation code and a button tap never come here (the webhook handles those). Meta resends a payload it
 * thinks was not received, so a message is kept once per wamid. Answers are free-form text, so they go out only
 * inside the 24-hour window the person opened (PlatformConversation::canReply).
 */
final class PlatformInbox
{
    public const ALERT_MINUTES = 30;

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
        $type = mb_substr((string) ($message['type'] ?? 'text'), 0, 20);

        return $this->keep(PlatformConversation::WHATSAPP, $phone, $this->profileName($contacts, $phone), $providerId, $type, self::text($message, $type));
    }

    /** One message to the EgHub page on Messenger or Instagram. */
    public function receiveFromPage(string $channel, string $sender, string $providerId, string $type, string $text): ?PlatformConversationMessage
    {
        if (! preg_match('/^\d{1,64}$/', $sender)) {
            return null;
        }
        $label = self::TYPE_LABELS[$type] ?? '';

        return $this->keep($channel, $sender, null, $providerId, $type, trim(($label !== '' ? "[{$label}] " : '').$text));
    }

    /** A person of the team answered in Meta's own inbox: the team sees it here, and the automatic answer goes quiet. */
    public function answeredOnMeta(string $channel, string $recipient, string $providerId, string $text): void
    {
        $conversation = PlatformConversation::query()->where('channel', $channel)->where('phone', $recipient)->first();
        if (! $conversation || PlatformConversationMessage::query()->where('provider_id', $providerId)->exists()) {
            return;
        }
        $conversation->messages()->create(['direction' => 'note', 'body' => __('Answered in Meta: :text', ['text' => $text]), 'provider_id' => $providerId]);
        $conversation->forceFill(['bot_paused_until' => now()->addHours(PlatformAutoReply::PAUSE_HOURS), 'last_message_at' => now()])->save();
    }

    /** Called after the response: Meta sends only an id with the message. */
    public function fillPageName(PlatformConversation $conversation): void
    {
        $name = (new MetaPageDriver($conversation->channel))->profileName($conversation->phone);
        if ($name !== null) {
            $conversation->forceFill(['name' => $name])->save();
        }
    }

    private function keep(string $channel, string $address, ?string $name, string $providerId, string $type, string $body): ?PlatformConversationMessage
    {
        if ($providerId === '' || PlatformConversationMessage::query()->where('provider_id', $providerId)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($channel, $address, $name, $providerId, $type, $body) {
            $conversation = PlatformConversation::query()->firstOrCreate(['channel' => $channel, 'phone' => $address]);
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
        $row = $this->deliver($conversation, $admin, $text, fn () => $this->driver($conversation)->reply($conversation->phone, $text));
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
        return $this->deliver($conversation, null, $text, fn () => $this->driver($conversation)->replyWithButtons($conversation->phone, $text, $buttons));
    }

    /**
     * The team writes first to a new customer: only an approved template may open a conversation. The person's answer
     * opens the 24-hour window, and the automatic answer takes it from there.
     */
    public function start(string $phone, string $name, PlatformAdmin $admin): PlatformConversationMessage
    {
        $conversation = PlatformConversation::query()->firstOrCreate(['channel' => PlatformConversation::WHATSAPP, 'phone' => $phone], ['name' => $name]);
        $text = str_replace('{{1}}', $name, self::WELCOME_TEMPLATE);

        return $this->deliver($conversation, $admin, $text, fn () => (new MetaCloudDriver)->send($phone, 'welcome', [$name]));
    }

    private function driver(PlatformConversation $conversation): MetaCloudDriver|MetaPageDriver
    {
        return $conversation->isWhatsApp() ? new MetaCloudDriver : new MetaPageDriver($conversation->channel);
    }

    /** @param  \Closure(): string  $send */
    private function deliver(PlatformConversation $conversation, ?PlatformAdmin $admin, string $text, \Closure $send): PlatformConversationMessage
    {
        $row = $conversation->messages()->create(['direction' => 'out', 'body' => $text, 'status' => 'sending', 'admin_id' => $admin?->id]);
        try {
            $id = $send();
            $row->update(['status' => 'sent', 'provider_id' => mb_substr($id, 0, 255)]);
        } catch (WhatsAppException $e) {
            $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)]);
        }
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $row;
    }

    /**
     * Tells the team by email that a person waits: whoever follows the conversation, else everyone who answers here.
     * One email per conversation in ALERT_MINUTES, so a person writing several messages sends one.
     */
    public function alertTeam(PlatformConversation $conversation): void
    {
        if (! Cache::add('inbox:alert:'.$conversation->id, 1, now()->addMinutes(self::ALERT_MINUTES))) {
            return;
        }
        $assignee = $conversation->assignee;
        Notification::send($assignee?->is_active ? collect([$assignee]) : PlatformAdmin::notifiable('inbox.manage'), new InboxNeedsAnswer($conversation));
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
