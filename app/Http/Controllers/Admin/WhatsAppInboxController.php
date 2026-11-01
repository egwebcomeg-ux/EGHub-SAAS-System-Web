<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Services\Assistant;
use App\Services\WhatsApp\PlatformAutoReply;
use App\Services\WhatsApp\PlatformInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «محادثات واتساب» (inbox.manage): the people who wrote to the EgHub number, the team's answers from it, notes only
 * the team sees, who follows each conversation, and ready answers.
 */
class WhatsAppInboxController extends Controller
{
    public function index(Request $request): View
    {
        return $this->page($request, null);
    }

    /** Opening a conversation marks it read. */
    public function show(Request $request, PlatformConversation $conversation): View
    {
        if ($conversation->unread > 0) {
            $conversation->forceFill(['unread' => 0])->save();
        }

        return $this->page($request, $conversation);
    }

    public function reply(Request $request, PlatformConversation $conversation, PlatformInbox $inbox): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:4096']);
        if (! $conversation->canReply()) {
            return back()->withInput()->withErrors(['body' => __('More than 24 hours passed since their last message. WhatsApp allows only an approved template now.')]);
        }
        $row = $inbox->reply($conversation, $request->user('admin'), $data['body']);
        if ($row->status === 'failed') {
            return back()->withInput()->withErrors(['body' => __('WhatsApp did not send it: :error', ['error' => $row->error])]);
        }

        return back();
    }

    public function note(Request $request, PlatformConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $conversation->messages()->create(['direction' => 'note', 'body' => $data['body'], 'admin_id' => $request->user('admin')->id]);

        return back();
    }

    public function assign(Request $request, PlatformConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', Rule::in($this->team()->pluck('id')->all())]]);
        $conversation->update(['assigned_to' => $data['assigned_to'] ?? null]);

        return back();
    }

    public function status(Request $request, PlatformConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $conversation->update(['status' => $data['status']]);

        return back();
    }

    /** The switch of the automatic answer, for the whole number. */
    public function autoReply(Request $request): RedirectResponse
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, $request->boolean('enabled') ? '1' : '0');

        return back();
    }

    /** The team is done here: the automatic answer may speak in this conversation again. */
    public function resumeBot(PlatformConversation $conversation): RedirectResponse
    {
        $conversation->update(['bot_paused_until' => null, 'assigned_to' => null]);

        return back();
    }

    public function storeCanned(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:80', 'body' => 'required|string|max:1000']);
        PlatformCannedReply::query()->create($data);

        return back()->with('ok', __('Ready answer saved.'));
    }

    public function destroyCanned(PlatformCannedReply $reply): RedirectResponse
    {
        $reply->delete();

        return back()->with('ok', __('Ready answer deleted.'));
    }

    private function page(Request $request, ?PlatformConversation $conversation): View
    {
        $filter = in_array($request->query('status'), ['closed', 'mine'], true) ? (string) $request->query('status') : 'open';
        $search = trim((string) $request->query('q', ''));
        $admin = $request->user('admin');

        return view('admin.whatsapp-inbox', [
            'conversations' => PlatformConversation::query()->with('assignee')
                ->when($filter === 'mine', fn (Builder $q) => $q->where('status', 'open')->where('assigned_to', $admin->id))
                ->when($filter !== 'mine', fn (Builder $q) => $q->where('status', $filter))
                ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('phone', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
                ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30)->withQueryString(),
            'conversation' => $conversation,
            'messages' => $conversation?->messages()->with('admin')->orderBy('id')->get()->take(-200) ?? collect(),
            'team' => $this->team(),
            'canned' => PlatformCannedReply::query()->orderBy('title')->get(),
            'filter' => $filter,
            'autoReplyOn' => PlatformSetting::get(PlatformAutoReply::SETTING) === '1',
            'assistantReady' => Assistant::enabled(),
            'search' => $search,
        ]);
    }

    /** @return Collection<int, PlatformAdmin> */
    private function team(): Collection
    {
        return PlatformAdmin::query()->where('is_active', true)->orderBy('name')->get()->filter(fn (PlatformAdmin $a) => $a->allows('inbox.manage'))->values();
    }
}
