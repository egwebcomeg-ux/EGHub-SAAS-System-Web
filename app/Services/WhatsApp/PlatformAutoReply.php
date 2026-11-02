<?php

namespace App\Services\WhatsApp;

use App\Models\AssistantMessage;
use App\Models\MenuItem;
use App\Models\PlatformAdmin;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Assistant;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;

/**
 * The automatic answer on the EgHub number and page: App\Services\Assistant answers from the live plans and add-ons, so a price
 * is never invented. It stays quiet where a person of the team has the conversation (assigned by hand, or answered in
 * the last 24 hours), and hands over when the person asks for someone, when it cannot answer, on media it cannot read,
 * when the model is down, or after DAILY_LIMIT answers to one person, and then gives the conversation to the team member
 * with the fewest open ones. Until a person answers it keeps answering what it can, on every channel, and says only once
 * that the team will answer. The first message of a new person gets a welcome with buttons. Off until the team switches it on in «محادثات واتساب».
 */
final class PlatformAutoReply
{
    public const SETTING = 'whatsapp_auto_reply';

    public const PAUSE_HOURS = 24;

    /** Keeps one person from running up the model's bill. */
    public const DAILY_LIMIT = 15;

    private const MAX_TEAM_ANSWERS = 100;

    /** What a person is told when the team takes over and the model has no words of its own. */
    public const HANDOVER_TEXT = 'وصلتنا رسالتك، وحد من فريق EgHub هيرد عليك هنا في أقرب وقت.';

    public const HANDOVER_NOTE = 'المحادثة مستنية رد من الفريق. الرد الآلي بيجاوب على الأسئلة لحد ما حد يرد.';

    public const BUTTON_PREFIX = 'inbox:';

    public const WELCOME_TEXT = 'أهلًا بيك في EgHub، المنيو الإلكتروني والطلبات للمطاعم والكافيهات. اكتب سؤالك هنا أو اختار من الأزرار، ولو حابب تكلم حد من الفريق هيرد عليك هنا على نفس الرقم.';

    public const PAGE_WELCOME_TEXT = 'أهلًا بيك في EgHub، المنيو الإلكتروني والطلبات للمطاعم والكافيهات. اكتب سؤالك هنا أو اختار من الأزرار، ولو حابب تكلم حد من الفريق هيرد عليك هنا في نفس المحادثة.';

    /** The titles are the questions: a tap reaches the assistant like a written message, the last one hands over. */
    private const WELCOME_BUTTONS = [
        self::BUTTON_PREFIX.'prices' => 'الأسعار والباقات',
        self::BUTTON_PREFIX.'features' => 'المميزات',
        self::BUTTON_PREFIX.'team' => 'أكلم حد من الفريق',
    ];

    private const GREETING = '/^(?:[\p{P}\s]*(?:و?عليكم السلام|السلام عليكم|ورحمة الله|وبركاته|أهلا|اهلا|أهلًا|هاي|هلا|مرحبا|سلام|صباح الخير|مساء الخير|ازيك|إزيك|hi|hello|hey)[\p{P}\s]*)+$/iu';

    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'location', 'contacts'];

    private const WAITING_RULES = <<<'TXT'
The team has already been told and will answer in this chat. Answer what you can from your facts and do not say again that the team will answer. If you cannot answer, end your answer with [[HUMAN]].
TXT;

    private const RESTAURANT_RULES = <<<'TXT'
NEW RESTAURANT: This person is the owner of «:name», which signed up on EgHub. Its menu link is :url and its dashboard is :dashboard. It has :items menu items and a logo: :logo. Your job is to help them finish, one request at a time, and to wait for the answer before the next:
1. No items yet: ask them to send the menu here as a photo or a PDF, or tell them to upload it from the dashboard.
2. No logo yet: ask them to send it.
3. Then ask how many tables they have, and tell them that the QR codes print from the dashboard.
Thank them for what they send and say that the team will add it. Answer their questions from your facts. Never say that something was added or is ready unless these details show it.
TXT;

    private const CHANNEL_RULES = <<<'TXT'
CHANNEL: This conversation is on WhatsApp, on EgHub's own number. The person is already talking to EgHub here: never give a wa.me link or another WhatsApp number. When the team is needed, say that someone from the team will answer here in this chat. Never claim to be a human; if asked, say you are EgHub's automatic assistant. If they ask how to reach support or for a phone number, say this same number is EgHub's main number and that writing «أكلم حد من الفريق» here brings a person; never give any personal number. Do not promise working hours or a response time. If they ask to delete their messages or data, say the team will do it and end your answer with [[HUMAN]].
TXT;

    private const PAGE_RULES = <<<'TXT'
CHANNEL: This conversation is on :channel, in the chat of EgHub's own page. The person is already talking to EgHub here: never give a wa.me link. When the team is needed, say that someone from the team will answer here in this chat. Never claim to be a human; if asked, say you are EgHub's automatic assistant. If they ask for a phone number, give only EgHub's main number from your instructions, and say that writing «أكلم حد من الفريق» here brings a person; never give any personal number. Do not promise working hours or a response time. If they ask to delete their messages or data, say the team will do it and end your answer with [[HUMAN]].
TXT;

    public function __construct(private PlatformInbox $inbox) {}

    public static function enabled(): bool
    {
        return PlatformSetting::get(self::SETTING) === '1' && Assistant::enabled();
    }

    public function shouldAnswer(PlatformConversation $conversation): bool
    {
        return self::enabled() && ($conversation->assigned_to === null || $conversation->waitingForTeam()) && ! $conversation->botPaused() && $conversation->canReply();
    }

    /** Reached from the queue: the conversation is checked again, the team may have answered meanwhile. */
    public function answer(PlatformConversationMessage $message): void
    {
        $conversation = $message->conversation;
        if ($message->direction !== 'in' || ! $this->shouldAnswer($conversation)) {
            return;
        }
        // several messages in a row get one answer, from the job of the last one
        if ($conversation->messages()->where('direction', 'in')->where('id', '>', $message->id)->exists()) {
            return;
        }
        if (! in_array($message->type, [...self::MEDIA_TYPES, 'text', 'interactive'], true)) {
            return; // a reaction or a system notice asks for nothing
        }
        $isFirst = ! $conversation->messages()->where('direction', 'out')->exists();
        // a hello gets the choices at any time, never the model: it would answer an older question still in the conversation
        $isGreeting = ! $conversation->tenant_id && $message->type === 'text' && preg_match(self::GREETING, (string) $message->body);
        if (($isFirst || $isGreeting) && $this->answersToday($conversation) < self::DAILY_LIMIT) {
            $this->inbox->replyWithButtons($conversation, $conversation->isWhatsApp() ? self::WELCOME_TEXT : self::PAGE_WELCOME_TEXT, self::WELCOME_BUTTONS);
            if ($isGreeting) {
                return;
            }
        }
        if (in_array($message->type, self::MEDIA_TYPES, true) || $this->answersToday($conversation) >= self::DAILY_LIMIT) {
            $this->handOver($conversation, self::HANDOVER_TEXT);

            return;
        }
        $turns = $this->turns($conversation);
        try {
            $answer = Assistant::reply($turns, 'ar', $this->channelRules($conversation));
        } catch (\Throwable $e) {
            Log::warning('whatsapp auto reply failed: '.$e->getMessage());
            $this->handOver($conversation, self::HANDOVER_TEXT);

            return;
        }
        $text = trim((string) preg_replace('~(?:https?://)?wa\.me/\S*~i', '', $answer['text']));
        if ($text === '') {
            $text = self::HANDOVER_TEXT;
        }

        AssistantMessage::query()->create([
            'conversation' => (['messenger' => 'fb-', 'instagram' => 'ig-'][$conversation->channel] ?? 'wa-').$conversation->id, 'visitor' => hash('sha256', $conversation->phone.'|'.config('app.key')),
            'lang' => 'ar', 'question' => end($turns)['content'], 'answer' => $text,
            'handoff' => $answer['handoff'], 'tokens_in' => $answer['in'], 'tokens_out' => $answer['out'],
        ]);

        if ($answer['handoff']) {
            $this->handOver($conversation, $text);

            return;
        }
        $this->inbox->reply($conversation, null, $text);
    }

    /** The rules of this channel, and the team's own ready answers: what they write there the assistant can say. */
    /** What the bot asks a restaurant's owner for, from that restaurant's own state. */
    private function restaurantRules(PlatformConversation $conversation): string
    {
        $tenant = $conversation->tenant_id ? Tenant::query()->find($conversation->tenant_id) : null;
        if (! $tenant) {
            return '';
        }
        [$items, $logo] = $tenant->run(fn () => [MenuItem::query()->count(), filled(Settings::get('logo'))]);

        return "\n".strtr(self::RESTAURANT_RULES, [
            ':name' => $tenant->name, ':url' => $tenant->url(), ':dashboard' => platform_url('app', '/'),
            ':items' => (string) $items, ':logo' => $logo ? 'yes' : 'no',
        ]);
    }

    private function channelRules(PlatformConversation $conversation): string
    {
        $answers = PlatformCannedReply::query()->orderBy('title')->limit(self::MAX_TEAM_ANSWERS)->get()
            ->map(fn (PlatformCannedReply $reply) => '- '.$reply->title.': '.$reply->body)->implode("\n");

        $rules = $conversation->isWhatsApp() ? self::CHANNEL_RULES : str_replace(':channel', ucfirst($conversation->channel), self::PAGE_RULES);

        $rules .= $conversation->waitingForTeam() ? "\n".self::WAITING_RULES : '';
        $rules .= $this->restaurantRules($conversation);

        return $rules.($answers !== '' ? "\n\nTEAM ANSWERS (written by the EgHub team, facts you may use):\n".$answers : '');
    }

    /** Fewest open conversations first, so people are shared across the team and no personal number is ever given out. */
    private function nextTeamMember(): ?PlatformAdmin
    {
        return PlatformAdmin::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (PlatformAdmin $admin) => $admin->allows('inbox.manage'))
            ->sortBy(fn (PlatformAdmin $admin) => PlatformConversation::query()->where('status', 'open')->where('assigned_to', $admin->id)->count())
            ->first();
    }

    private function handOver(PlatformConversation $conversation, string $text): void
    {
        if ($conversation->waitingForTeam()) {
            $this->inbox->alertTeam($conversation); // the person was told already

            return;
        }
        $this->inbox->reply($conversation, null, $text);
        $conversation->assigned_to = $this->nextTeamMember()?->id;
        $conversation->messages()->create(['direction' => 'note', 'body' => self::HANDOVER_NOTE]);
        $conversation->forceFill(['waiting_for_team_since' => now()])->save();
        $this->inbox->alertTeam($conversation);
    }

    private function answersToday(PlatformConversation $conversation): int
    {
        return $conversation->messages()->where('direction', 'out')->whereNull('admin_id')->where('created_at', '>', now()->subDay())->count();
    }

    /**
     * The conversation as the model's turns, ending with the person: messages in a row become one turn.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function turns(PlatformConversation $conversation): array
    {
        $rows = $conversation->messages()->whereIn('direction', ['in', 'out'])->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'failed'))
            ->orderByDesc('id')->limit(Assistant::MAX_TURNS)->get()->reverse();
        $turns = [];
        foreach ($rows as $row) {
            $role = $row->direction === 'in' ? 'user' : 'assistant';
            $content = trim(mb_substr((string) $row->body, 0, Assistant::MAX_CHARS));
            if ($content === '') {
                continue;
            }
            if ($turns !== [] && $turns[array_key_last($turns)]['role'] === $role) {
                $turns[array_key_last($turns)]['content'] .= "\n".$content;

                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }
        // the welcome just sent comes after the question: the model must be asked with the person's words last
        while ($turns !== [] && $turns[array_key_last($turns)]['role'] !== 'user') {
            array_pop($turns);
        }

        return $turns;
    }
}
