<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Services\Assistant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What visitors asked the website assistant and what it answered, with the hand-offs to WhatsApp. */
class AssistantController extends Controller
{
    public function index(Request $request): View
    {
        $only = $request->query('handoff') === '1';
        $q = trim((string) $request->query('q', ''));
        $since = now()->subDays(30);

        return view('admin.assistant', [
            'rows' => AssistantMessage::query()
                ->when($only, fn ($w) => $w->where('handoff', true))
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('question', 'like', "%$q%")->orWhere('answer', 'like', "%$q%")))
                ->latest('id')->paginate(50)->withQueryString(),
            'stats' => [
                'today' => AssistantMessage::query()->where('created_at', '>=', local_day_start())->count(),
                'month' => AssistantMessage::query()->where('created_at', '>=', $since)->count(),
                'chats' => AssistantMessage::query()->where('created_at', '>=', $since)->distinct('conversation')->count('conversation'),
                'handoff' => AssistantMessage::query()->where('created_at', '>=', $since)->where('handoff', true)->count(),
                'tokens' => (int) AssistantMessage::query()->where('created_at', '>=', $since)->sum('tokens_in') + (int) AssistantMessage::query()->where('created_at', '>=', $since)->sum('tokens_out'),
            ],
            'only' => $only, 'q' => $q, 'enabled' => Assistant::enabled(), 'model' => Assistant::model(),
            'provider' => Assistant::provider() === 'gemini' ? 'Google Gemini' : 'Claude',
        ]);
    }
}
