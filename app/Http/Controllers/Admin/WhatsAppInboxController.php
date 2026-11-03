<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\PlatformCannedReply;
use App\Models\PlatformConversation;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assistant;
use App\Services\WelcomeKit;
use App\Services\WhatsApp\Drivers\MetaPageDriver;
use App\Services\WhatsApp\PlatformAutoReply;
use App\Services\WhatsApp\PlatformInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «المحادثات» (inbox.manage): the people who wrote to the EgHub number or page, the team's answers, notes only
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
            return back()->withInput()->withErrors(['body' => __('More than 24 hours passed since their last message, so an answer cannot be sent now.')]);
        }
        $row = $inbox->reply($conversation, $request->user('admin'), $data['body']);
        if ($row->status === 'failed') {
            return back()->withInput()->withErrors(['body' => __(':channel did not send it: :error', ['channel' => $conversation->channelLabel(), 'error' => $row->errorText()])]);
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
        $conversation->update(['assigned_to' => $data['assigned_to'] ?? null, 'waiting_for_team_since' => null]);

        return back();
    }

    public function status(Request $request, PlatformConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $conversation->update(['status' => $data['status']]);

        return back();
    }

    /**
     * «ابدأ محادثة»: the welcome template to a new customer. Someone who wrote in the last 24 hours is simply opened,
     * and a person already welcomed is not sent it twice in a day.
     */
    public function start(Request $request, PlatformInbox $inbox): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'], 'name' => 'required|string|max:60']);
        $phone = whatsapp_number($data['phone']);
        $existing = PlatformConversation::query()->where('channel', PlatformConversation::WHATSAPP)->where('phone', $phone)->first();
        if ($existing && ($existing->canReply() || $existing->messages()->where('direction', 'out')->where('status', '!=', 'failed')->where('created_at', '>', now()->subDay())->exists())) {
            return redirect()->route('admin.inbox.show', $existing);
        }
        if (PlatformInbox::welcomeIsWaiting()) {
            return back()->withInput()->withErrors(['phone' => __('Meta has not approved the welcome template yet, so a new conversation cannot start. It can answer whoever writes to us.')]);
        }
        $row = $inbox->start($phone, $data['name'], $request->user('admin'));
        if ($row->status === 'failed') {
            return redirect()->route('admin.inbox.show', $row->platform_conversation_id)->withErrors(['body' => __(':channel did not send it: :error', ['channel' => __('WhatsApp'), 'error' => $row->errorText()])]);
        }

        return redirect()->route('admin.inbox.show', $row->platform_conversation_id);
    }

    /** From a restaurant's page: the conversation with its owner opens, with the welcome when it is due. */
    public function startForRestaurant(Tenant $tenant, PlatformInbox $inbox, WelcomeKit $kit, Request $request): RedirectResponse
    {
        $owner = $kit->owner($tenant) ?? User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();
        if (! $owner?->phone) {
            return back()->withErrors(['conversation' => __('This restaurant has no owner mobile.')]);
        }
        $conversation = $inbox->forRestaurant($tenant, $owner->phone, $owner->name);
        if ($conversation->canReply() || $conversation->messages()->where('direction', 'out')->where('status', '!=', 'failed')->where('created_at', '>', now()->subDay())->exists()) {
            return redirect()->route('admin.inbox.show', $conversation);
        }
        if (PlatformInbox::welcomeIsWaiting()) {
            return redirect()->route('admin.inbox.show', $conversation)->with('ok', __('Meta has not approved the welcome template yet. This restaurant gets it by itself once it is approved.'));
        }
        $row = $inbox->start($conversation->phone, $owner->name, $request->user('admin'));
        if ($row->status === 'failed') {
            return redirect()->route('admin.inbox.show', $conversation)->withErrors(['body' => __(':channel did not send it: :error', ['channel' => __('WhatsApp'), 'error' => $row->errorText()])]);
        }

        return redirect()->route('admin.inbox.show', $conversation);
    }

    /** A person asked to have their messages removed (the privacy policy promises it). */
    public function destroy(Request $request, PlatformConversation $conversation): RedirectResponse
    {
        PlatformAuditLog::record($request->user('admin'), 'inbox.deleted', (string) $conversation->phone, ['channel' => $conversation->channel, 'name' => $conversation->name, 'tenant' => $conversation->tenant_id, 'messages' => $conversation->messages()->count()]);
        $conversation->delete();

        return redirect()->route('admin.inbox')->with('ok', __('Conversation deleted.'));
    }

    /** The switch of the automatic answer, for the whole number and page. */
    public function autoReply(Request $request): RedirectResponse
    {
        PlatformSetting::put(PlatformAutoReply::SETTING, $request->boolean('enabled') ? '1' : '0');
        PlatformAuditLog::record($request->user('admin'), 'inbox.auto_reply', $request->boolean('enabled') ? 'on' : 'off');

        return back();
    }

    /** The team is done here: the automatic answer may speak in this conversation again. */
    public function resumeBot(PlatformConversation $conversation): RedirectResponse
    {
        $conversation->update(['bot_paused_until' => null, 'assigned_to' => null, 'waiting_for_team_since' => null]);

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
            'messengerConnected' => MetaPageDriver::connected(PlatformConversation::MESSENGER),
            'instagramConnected' => MetaPageDriver::connected(PlatformConversation::INSTAGRAM),
            'search' => $search,
        ]);
    }

    /** @return Collection<int, PlatformAdmin> */
    private function team(): Collection
    {
        return PlatformAdmin::query()->where('is_active', true)->orderBy('name')->get()->filter(fn (PlatformAdmin $a) => $a->allows('inbox.manage'))->values();
    }
}
