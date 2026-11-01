<?php

namespace App\Services\WhatsApp;

use App\Models\AssistantMessage;
use App\Models\PlatformAdmin;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Models\PlatformSetting;
use App\Services\Assistant;
use Illuminate\Support\Facades\Log;

/**
 * The automatic answer on the EgHub number: App\Services\Assistant answers from the live plans and add-ons, so a price
 * is never invented. It stays quiet where the team has the conversation (assigned, or answered in the last 24 hours),
 * and hands over when the person asks for someone, when it cannot answer, on media it cannot read, when the model is
 * down, or after DAILY_LIMIT answers to one person, and then gives the conversation to the team member with the fewest
 * open ones. The first message of a new person gets a welcome with buttons. Off until the team switches it on in «محادثات واتساب».
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

    public const HANDOVER_NOTE = 'الرد الآلي سكت هنا والمحادثة مستنية رد من الفريق.';

    public const BUTTON_PREFIX = 'inbox:';

    public const WELCOME_TEXT = 'أهلًا بيك في EgHub، المنيو الإلكتروني والطلبات للمطاعم والكافيهات. اكتب سؤالك هنا أو اختار من الأزرار، ولو حابب تكلم حد من الفريق هيرد عليك هنا على نفس الرقم.';

    /** The titles are the questions: a tap reaches the assistant like a written message, the last one hands over. */
    private const WELCOME_BUTTONS = [
        self::BUTTON_PREFIX.'prices' => 'الأسعار والباقات',
        self::BUTTON_PREFIX.'features' => 'المميزات',
        self::BUTTON_PREFIX.'team' => 'أكلم حد من الفريق',
    ];

    private const GREETING = '/^(?:[\p{P}\s]*(?:أهلا|اهلا|أهلًا|هاي|هلا|مرحبا|سلام|السلام عليكم|صباح الخير|مساء الخير|ازيك|إزيك|hi|hello|hey)[\p{P}\s]*)+$/iu';

    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'location', 'contacts'];

    private const CHANNEL_RULES = <<<'TXT'
CHANNEL: This conversation is on WhatsApp, on EgHub's own number. The person is already talking to EgHub here: never give a wa.me link or another WhatsApp number. When the team is needed, say that someone from the team will answer here in this chat. Never claim to be a human; if asked, say you are EgHub's automatic assistant. If they ask how to reach support or for a phone number, say this same number is EgHub's main number and that writing «أكلم حد من الفريق» here brings a person; never give any personal number. Do not promise working hours or a response time.
TXT;

    public function __construct(private PlatformInbox $inbox) {}

    public static function enabled(): bool
    {
        return PlatformSetting::get(self::SETTING) === '1' && Assistant::enabled();
    }

    public function shouldAnswer(PlatformConversation $conversation): bool
    {
        return self::enabled() && $conversation->assigned_to === null && ! $conversation->botPaused() && $conversation->canReply();
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
        if ($isFirst) {
            $this->inbox->replyWithButtons($conversation, self::WELCOME_TEXT, self::WELCOME_BUTTONS);
            if ($message->type === 'text' && preg_match(self::GREETING, (string) $message->body)) {
                return; // the welcome is the answer to a hello
            }
        }
        if (in_array($message->type, self::MEDIA_TYPES, true) || $this->answersToday($conversation) >= self::DAILY_LIMIT) {
            $this->handOver($conversation, self::HANDOVER_TEXT);

            return;
        }
        $turns = $this->turns($conversation);
        try {
            $answer = Assistant::reply($turns, 'ar', $this->channelRules());
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
            'conversation' => 'wa-'.$conversation->id, 'visitor' => hash('sha256', $conversation->phone.'|'.config('app.key')),
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
    private function channelRules(): string
    {
        $answers = PlatformCannedReply::query()->orderBy('title')->limit(self::MAX_TEAM_ANSWERS)->get()
            ->map(fn (PlatformCannedReply $reply) => '- '.$reply->title.': '.$reply->body)->implode("\n");

        return self::CHANNEL_RULES.($answers !== '' ? "\n\nTEAM ANSWERS (written by the EgHub team, facts you may use):\n".$answers : '');
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
        $this->inbox->reply($conversation, null, $text);
        $conversation->assigned_to = $this->nextTeamMember()?->id;
        $conversation->messages()->create(['direction' => 'note', 'body' => self::HANDOVER_NOTE]);
        $conversation->forceFill(['bot_paused_until' => now()->addHours(self::PAUSE_HOURS)])->save();
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

        return $turns;
    }
}
