<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Services\Assistant;
use App\Services\DashboardAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /assistant on the dashboard: one turn of «اسألني» (App\Services\DashboardAssistant). The browser keeps the
 * conversation and sends it back; every question is kept with the website assistant's (conversation "dash-{place}-…")
 * so the team sees what owners ask.
 */
class AssistantController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $wa = DashboardAssistant::supportWhatsApp();
        if (! Assistant::enabled()) {
            return response()->json(['error' => 'unavailable', 'whatsapp' => $wa], 503);
        }
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
        if (! DashboardAssistant::take()) {
            return response()->json(['error' => 'limit', 'whatsapp' => $wa], 429);
        }
        $lang = app()->getLocale() === 'en' ? 'en' : 'ar';

        try {
            $r = DashboardAssistant::reply($request->user(), $messages, $lang);
        } catch (\Throwable $e) {
            Log::warning('dashboard assistant failed: '.$e->getMessage());

            return response()->json(['error' => 'failed', 'whatsapp' => $wa], 502);
        }

        $chat = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('conversation', ''));
        AssistantMessage::query()->create([
            'conversation' => mb_substr('dash-'.tenant()->getTenantKey().'-'.$chat, 0, 40),
            'visitor' => hash('sha256', 'user:'.$request->user()->id.'|'.config('app.key')),
            'lang' => $lang, 'question' => end($messages)['content'], 'answer' => $r['text'],
            'handoff' => $r['handoff'], 'tokens_in' => $r['in'], 'tokens_out' => $r['out'],
        ]);

        return response()->json(['reply' => $r['text'], 'links' => $r['links'], 'handoff' => $r['handoff'], 'whatsapp' => $wa]);
    }
}
