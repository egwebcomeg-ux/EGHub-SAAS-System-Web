<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Services\Assistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** POST /assistant: one turn of the website assistant. The browser keeps the conversation and sends it back. */
class AssistantController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        if (! Assistant::enabled()) {
            return response()->json(['error' => 'unavailable'], 503);
        }
        $lang = $request->input('lang') === 'en' ? 'en' : 'ar';
        $conversation = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('conversation', '')), 0, 40);

        // Only well-formed turns, alternating, ending with the visitor; long chats keep their last turns.
        $messages = [];
        foreach (array_slice((array) $request->input('messages', []), -Assistant::MAX_TURNS) as $m) {
            $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $content = trim(mb_substr((string) ($m['content'] ?? ''), 0, Assistant::MAX_CHARS));
            if ($content === '' || ($messages && end($messages)['role'] === $role)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }
        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        if (! $messages || end($messages)['role'] !== 'user') {
            return response()->json(['error' => 'empty'], 422);
        }

        try {
            $r = Assistant::reply($messages, $lang);
        } catch (\Throwable $e) {
            Log::warning('assistant failed: '.$e->getMessage());

            return response()->json(['error' => 'failed', 'whatsapp' => Assistant::salesWhatsApp()], 502);
        }

        AssistantMessage::query()->create([
            'conversation' => $conversation, 'visitor' => hash('sha256', $request->ip().'|'.config('app.key')),
            'lang' => $lang, 'question' => end($messages)['content'], 'answer' => $r['text'],
            'handoff' => $r['handoff'], 'tokens_in' => $r['in'], 'tokens_out' => $r['out'],
        ]);

        return response()->json(['reply' => $r['text'], 'handoff' => $r['handoff']]);
    }
}
