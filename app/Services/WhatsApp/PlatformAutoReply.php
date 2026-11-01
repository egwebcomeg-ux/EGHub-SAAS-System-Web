<?php

namespace App\Services\WhatsApp;

use App\Models\AssistantMessage;
use App\Models\PlatformConversation;
use App\Models\PlatformConversationMessage;
use App\Models\PlatformSetting;
use App\Services\Assistant;
use Illuminate\Support\Facades\Log;

/**
 * The automatic answer on the EgHub number: App\Services\Assistant answers from the live plans and add-ons, so a price
 * is never invented. It stays quiet where the team has the conversation (assigned, or answered in the last 24 hours),
 * and hands over when the person asks for someone, when it cannot answer, on media it cannot read, when the model is
 * down, or after DAILY_LIMIT answers to one person. Off until the team switches it on in «محادثات واتساب».
 */
final class PlatformAutoReply
{
    public const SETTING = 'whatsapp_auto_reply';

    public const PAUSE_HOURS = 24;

    /** Keeps one person from running up the model's bill. */
    public const DAILY_LIMIT = 15;

    /** What a person is told when the team takes over and the model has no words of its own. */
    public const HANDOVER_TEXT = 'وصلتنا رسالتك، وحد من فريق EgHub هيرد عليك هنا في أقرب وقت.';

    public const HANDOVER_NOTE = 'الرد الآلي سكت هنا والمحادثة مستنية رد من الفريق.';

    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'location', 'contacts'];

    private const CHANNEL_RULES = <<<'TXT'
CHANNEL: This conversation is on WhatsApp, on EgHub's own number. The person is already talking to EgHub here: never give a wa.me link or another WhatsApp number. When the team is needed, say that someone from the team will answer here in this chat. Never claim to be a human; if asked, say you are EgHub's automatic assistant.
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
        if (in_array($message->type, self::MEDIA_TYPES, true) || $this->answersToday($conversation) >= self::DAILY_LIMIT) {
            $this->handOver($conversation, self::HANDOVER_TEXT);

            return;
        }
        if ($message->type !== 'text') {
            return; // a reaction or a system notice asks for nothing
        }

        $turns = $this->turns($conversation);
        try {
            $answer = Assistant::reply($turns, 'ar', self::CHANNEL_RULES);
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

    private function handOver(PlatformConversation $conversation, string $text): void
    {
        $this->inbox->reply($conversation, null, $text);
        $conversation->messages()->create(['direction' => 'note', 'body' => self::HANDOVER_NOTE]);
        $conversation->forceFill(['bot_paused_until' => now()->addHours(self::PAUSE_HOURS)])->save();
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
