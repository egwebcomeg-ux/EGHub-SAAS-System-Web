<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformAdmin;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Notifications\InboxNeedsAnswer;
use App\Services\Crm\AdLeads;
use App\Services\TenantProvisioner;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use App\Services\WhatsApp\Drivers\MetaPageDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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

    public const WELCOME_TRIES = 3;

    /** The text of the approved template egmenu_welcome, as the team reads it in the conversation. */
    public const WELCOME_TEMPLATE = 'أهلًا {{1}}، معاك فريق EgHub للمنيو الإلكتروني والطلبات للمطاعم والكافيهات. لو حابب تعرف الأسعار أو تبدأ، رد على الرسالة دي وهنساعدك.';

    /** The text of the Utility template restaurant_welcome (submitted by PlatformTemplates), as the team reads it. */
    public const RESTAURANT_WELCOME_TEMPLATE = 'أهلًا {{1}}، حساب {{2}} على EgHub اتعمل. تقدر تكمّل المنيو والإعدادات من لوحة التحكم، ولو محتاج مساعدة في أي خطوة رد على الرسالة دي.';

    /** The text of the Utility template follow_up (submitted by PlatformTemplates): {{2}} is what the team wrote. */
    public const FOLLOW_UP_TEMPLATE = "أهلًا {{1}}، معاك فريق EgHub بنتابع معاك:\n{{2}}\nلو حابب تكمّل، رد على الرسالة دي.";

    /** The longest text the team may put in the follow-up (Meta caps the whole body at 1024 characters). */
    public const FOLLOW_UP_MAX = 700;

    /** How long a person who commented on a post is not answered again on that post. */
    public const COMMENT_DAYS = 30;

    /** The private message to a person who commented on the page's post or ad; :name has its leading space. */
    public const COMMENT_MESSAGE = 'أهلًا:name 👋 شكرًا على تعليقك. EgHub بيعمل لمطعمك أو الكافيه بتاعك منيو QR مجاني بالعربي والإنجليزي، وطلبات أونلاين من غير عمولة، ومعاه :days يوم من الباقة الكاملة هدية. تقدر تسجّل في دقيقتين من هنا: :link'."\n".'أو اكتب سؤالك هنا أو اختار من الأزرار وهنرد عليك.';

    /** The public answer under the comment, one picked at random so the page does not repeat itself. */
    private const COMMENT_PUBLIC_REPLIES = [
        PlatformConversation::MESSENGER => ['بعتنالك التفاصيل على الماسنجر 📩', 'شكرًا لتعليقك! بعتنالك كل التفاصيل في رسالة على الماسنجر 👍', 'أهلًا بيك 🙌 شوف رسايل الماسنجر، بعتنالك التفاصيل هناك.'],
        PlatformConversation::INSTAGRAM => ['بعتنالك التفاصيل في الدايركت 📩', 'شكرًا لتعليقك! بعتنالك كل التفاصيل في الدايركت 👍', 'أهلًا بيك 🙌 شوف الدايركت، بعتنالك التفاصيل هناك.'],
    ];

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
        $row = $this->keep(PlatformConversation::WHATSAPP, $phone, $this->profileName($contacts, $phone), $providerId, $type, self::text($message, $type));
        // the first message of a person who tapped «send a WhatsApp message» on one of our ads carries the ad
        if ($row && is_array($message['referral'] ?? null)) {
            app(AdLeads::class)->record($row->conversation, $message['referral']);
        }

        return $row;
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

    /**
     * A comment on a post or an ad of the EgHub page (Facebook or Instagram): the person gets the one private message
     * Meta allows and a public answer under the comment says so; their answer opens the chat, where the automatic answer
     * takes over. Once per person and post, so a thread of comments is not answered again. The comment shows in the
     * conversation, but it does not open the 24-hour window: only the person's own message does.
     *
     * @return bool whether the private message went out
     */
    public function answerComment(string $channel, string $commentId, string $postId, string $from, string $name, string $text): bool
    {
        $providerId = 'comment:'.$commentId;
        if ($commentId === '' || PlatformSetting::get(PlatformAutoReply::SETTING) !== '1'
            || PlatformConversationMessage::query()->where('provider_id', $providerId)->exists()
            || ! Cache::add('inbox:comment:'.$channel.':'.$from.':'.$postId, 1, now()->addDays(self::COMMENT_DAYS))) {
            return false;
        }
        $driver = new MetaPageDriver($channel);
        $message = strtr(self::COMMENT_MESSAGE, [
            ':name' => $name !== '' ? ' '.Str::before($name, ' ') : '', ':days' => (string) TenantProvisioner::TRIAL_DAYS,
            ':link' => route('register', ['utm_source' => $channel, 'utm_medium' => 'comment']),
        ]);
        try {
            $sent = $driver->privateReply($commentId, $message, PlatformAutoReply::WELCOME_BUTTONS);
        } catch (WhatsAppException $e) {
            Log::warning('comment private reply failed: '.$e->getMessage());

            return false;
        }
        $address = preg_match('/^\d{1,64}$/', $sent['recipient']) ? $sent['recipient'] : $from;
        if (! preg_match('/^\d{1,64}$/', $address)) {
            return true;
        }
        DB::transaction(function () use ($channel, $address, $name, $providerId, $text, $message, $sent) {
            $conversation = PlatformConversation::query()->firstOrCreate(['channel' => $channel, 'phone' => $address], ['name' => $name !== '' ? mb_substr($name, 0, 120) : null]);
            $conversation->messages()->create(['direction' => 'in', 'type' => 'comment', 'body' => trim('[تعليق] '.$text), 'provider_id' => $providerId]);
            $conversation->messages()->create(['direction' => 'out', 'body' => $message, 'status' => 'sent', 'provider_id' => mb_substr($sent['id'], 0, 255)]);
            $conversation->forceFill(['status' => 'open', 'last_message_at' => now()])->save();
        });
        try {
            $driver->commentReply($commentId, self::COMMENT_PUBLIC_REPLIES[$channel][array_rand(self::COMMENT_PUBLIC_REPLIES[$channel])]);
        } catch (WhatsAppException $e) {
            Log::warning('comment public reply failed: '.$e->getMessage());
        }

        return true;
    }

    /** A person of the team answered in Meta's own inbox: the team sees it here, and the automatic answer goes quiet. */
    public function answeredOnMeta(string $channel, string $recipient, string $providerId, string $text): void
    {
        $conversation = PlatformConversation::query()->where('channel', $channel)->where('phone', $recipient)->first();
        if (! $conversation || PlatformConversationMessage::query()->where('provider_id', $providerId)->exists()) {
            return;
        }
        $conversation->messages()->create(['direction' => 'note', 'body' => __('Answered in Meta: :text', ['text' => $text]), 'provider_id' => $providerId]);
        $conversation->forceFill(['bot_paused_until' => now()->addHours(PlatformAutoReply::PAUSE_HOURS), 'waiting_for_team_since' => null, 'last_message_at' => now()])->save();
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
            $conversation->forceFill(['bot_paused_until' => now()->addHours(PlatformAutoReply::PAUSE_HOURS), 'waiting_for_team_since' => null])->save();
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
    public function start(string $phone, string $name, ?PlatformAdmin $admin = null): PlatformConversationMessage
    {
        return $this->startWithTemplate($phone, $name, 'welcome', [$name], self::WELCOME_TEMPLATE, $admin);
    }

    /**
     * Opens a conversation with an approved template; $text is its body with {{1}}, {{2}}... as the team reads it.
     *
     * @param  list<string>  $params
     */
    public function startWithTemplate(string $phone, string $name, string $templateKey, array $params, string $text, ?PlatformAdmin $admin = null): PlatformConversationMessage
    {
        $conversation = PlatformConversation::query()->firstOrCreate(['channel' => PlatformConversation::WHATSAPP, 'phone' => $phone], ['name' => $name]);
        $replace = [];
        foreach ($params as $index => $value) {
            $replace['{{'.($index + 1).'}}'] = $value;
        }

        return $this->deliver($conversation, $admin, strtr($text, $replace), fn () => (new MetaCloudDriver)->send($phone, $templateKey, $params));
    }

    /** The conversation of a restaurant's owner, waiting for its welcome until the template is approved. */
    public function forRestaurant(Tenant $tenant, string $phone, string $name): PlatformConversation
    {
        $conversation = PlatformConversation::query()->firstOrCreate(['channel' => PlatformConversation::WHATSAPP, 'phone' => whatsapp_number($phone)], ['name' => $name]);
        $conversation->forceFill(['tenant_id' => $tenant->id])->save();

        return $conversation;
    }

    /**
     * A restaurant's owner is welcomed with the Utility template once Meta approved it (Meta does not cap it per
     * person), else with the general welcome.
     */
    public function welcomeRestaurant(PlatformConversation $conversation, string $ownerName, string $restaurantName, ?PlatformAdmin $admin = null): PlatformConversationMessage
    {
        if (self::restaurantWelcomeIsApproved()) {
            return $this->startWithTemplate($conversation->phone, $ownerName, 'restaurant_welcome', [$ownerName, $restaurantName], self::RESTAURANT_WELCOME_TEMPLATE, $admin);
        }

        return $this->start($conversation->phone, $ownerName, $admin);
    }

    public static function restaurantWelcomeIsApproved(): bool
    {
        return PlatformTemplates::isApproved((string) config('whatsapp.templates.restaurant_welcome'));
    }

    /**
     * The team's own words after the 24 hours closed, inside the approved follow-up template. Meta takes no line break,
     * tab or long run of spaces in a template's parameter, so the text goes on one line.
     */
    public function followUp(PlatformConversation $conversation, ?PlatformAdmin $admin, string $text): PlatformConversationMessage
    {
        $name = (string) ($conversation->name ? Str::before($conversation->name, ' ') : __('there'));
        $words = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::FOLLOW_UP_MAX);

        return $this->startWithTemplate($conversation->phone, $name, 'follow_up', [$name, $words], self::FOLLOW_UP_TEMPLATE, $admin);
    }

    public static function followUpIsApproved(): bool
    {
        return PlatformTemplates::isApproved((string) config('whatsapp.templates.follow_up'));
    }

    /** The welcome waits for Meta: the team cannot start a conversation until then. */
    public static function welcomeIsWaiting(): bool
    {
        return PlatformTemplates::isNotApprovedYet((string) config('whatsapp.templates.welcome'));
    }

    /**
     * @return int how many new restaurants were welcomed: each once, in their first week, and never a person already
     *             talking to us. A welcome that failed is tried again a day later (Meta asks not to sooner), up to
     *             WELCOME_TRIES times; with only the Marketing welcome, a person Meta capped (131049) is not tried again.
     */
    public function welcomeNewRestaurants(): int
    {
        $utility = self::restaurantWelcomeIsApproved();
        if ((! $utility && ! PlatformTemplates::isApproved((string) config('whatsapp.templates.welcome'))) || ! PlatformAutoReply::enabled()) {
            return 0;
        }
        $sent = 0;
        PlatformConversation::query()->whereNotNull('tenant_id')->where('created_at', '>=', now()->subDays(7))
            ->whereDoesntHave('messages', fn ($messages) => $messages->where('status', '!=', 'failed'))
            ->whereHas('messages', fn ($messages) => $messages->where('status', 'failed'), '<', self::WELCOME_TRIES)
            ->whereDoesntHave('messages', fn ($messages) => $messages->where('created_at', '>', now()->subDay()))
            ->when(! $utility, fn ($query) => $query->whereDoesntHave('messages', fn ($messages) => $messages->where('error', 'like', '%'.PlatformConversationMessage::MARKETING_LIMIT.'%')))
            ->each(function (PlatformConversation $conversation) use (&$sent) {
                $restaurant = Tenant::query()->find($conversation->tenant_id);
                $this->welcomeRestaurant($conversation, (string) $conversation->name, (string) ($restaurant->name ?? $conversation->name));
                $sent++;
            });

        return $sent;
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
