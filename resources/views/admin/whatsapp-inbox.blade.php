@extends('layouts.admin')
@section('title', __('Conversations') . ' · EgHub Admin')

@section('content')
@php($canAdministerInbox = auth('admin')->user()->allows('inbox.admin'))
<div class="mb-4">
    <h1 class="page-title">{{ __('Conversations') }}</h1>
    <p class="muted">{{ __('What people write to the EgHub number and page, and your answers. Sign-in codes do not show here.') }}</p>
    <p class="muted mt-1 text-sm" data-inbox-channels>{{ __('Messenger') }}: {{ $messengerConnected ? __('Connected') : __('Not connected yet') }} · {{ __('Instagram') }}: {{ $instagramConnected ? __('Connected') : __('Not connected yet') }}</p>
</div>

<section class="card mb-4 flex flex-wrap items-center justify-between gap-3" data-inbox-auto-reply>
    <div class="min-w-0">
        <h2 class="section-title">{{ __('Automatic answer') }}: {{ $autoReplyOn ? __('On') : __('Off') }}</h2>
        <p class="muted text-sm">{{ __('Answers prices and services from the live plans. It goes quiet when someone asks for the team or a person from the team answers.') }}</p>
        @if (! $assistantReady)<p class="mt-1 text-sm font-semibold text-red-700">{{ __('The assistant has no API key, so nothing is answered.') }}</p>@endif
    </div>
    @if ($canAdministerInbox)
        <form method="post" action="{{ route('admin.inbox.auto-reply') }}">
            @csrf
            <input type="hidden" name="enabled" value="{{ $autoReplyOn ? 0 : 1 }}">
            <button @class(['min-h-10 px-4', 'btn-ghost' => $autoReplyOn, 'btn-navy' => ! $autoReplyOn])>{{ $autoReplyOn ? __('Turn it off') : __('Turn it on') }}</button>
        </form>
    @endif
</section>

<section class="card mb-4" data-inbox-start>
    <h2 class="section-title">{{ __('Start a conversation') }}</h2>
    <p class="muted text-sm">{{ __('Sends the approved welcome to a new customer who agreed to hear from you. When they answer, the automatic answer takes over.') }}</p>
    <form method="post" action="{{ route('admin.inbox.start') }}" class="mt-2 grid gap-2 sm:grid-cols-3">
        @csrf
        <label class="sr-only" for="start-name">{{ __('Name') }}</label>
        <input id="start-name" name="name" required maxlength="60" value="{{ old('name', request('name')) }}" class="field min-h-10" placeholder="{{ __('Name') }}">
        <label class="sr-only" for="start-phone">{{ __('Mobile') }}</label>
        <input id="start-phone" name="phone" required maxlength="20" dir="ltr" value="{{ old('phone', request('phone')) }}" class="field min-h-10" placeholder="{{ __('Mobile') }}">
        <button class="btn-navy min-h-10">{{ __('Start a conversation') }}</button>
    </form>
    @error('phone')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
</section>

<div class="grid gap-4 lg:grid-cols-3">
    <section @class(['card space-y-3', 'hidden lg:block' => $conversation]) data-inbox-list>
        <nav class="flex gap-1 overflow-x-auto" aria-label="{{ __('Status') }}">
            @foreach (['open' => __('Open conversations'), 'mine' => __('Assigned to me'), 'closed' => __('Closed conversations')] as $key => $label)
                @php($on = $filter === $key)
                <a href="{{ route('admin.inbox', $key === 'open' ? [] : ['status' => $key]) }}" @if ($on) aria-current="page" @endif @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-bold',
                    'bg-navy-900 text-white' => $on, 'bg-white text-navy-700 ring-1 ring-navy-100 hover:bg-navy-50' => ! $on])>{{ $label }}</a>
            @endforeach
        </nav>
        <form method="get" action="{{ route('admin.inbox') }}">
            @if ($filter !== 'open')<input type="hidden" name="status" value="{{ $filter }}">@endif
            <label class="sr-only" for="inbox-q">{{ __('Search') }}</label>
            <input id="inbox-q" name="q" value="{{ $search }}" class="field min-h-10" placeholder="{{ __('Name or mobile') }}">
        </form>
        <ul class="divide-y divide-navy-100">
            @forelse ($conversations as $c)
                <li>
                    <a href="{{ route('admin.inbox.show', $c) }}" @class(['block rounded-lg p-3 hover:bg-navy-50', 'bg-navy-50' => $conversation?->id === $c->id]) data-inbox-row="{{ $c->id }}">
                        <span class="flex items-center justify-between gap-2">
                            <bdi class="min-w-0 truncate font-bold">{{ $c->displayName() }}</bdi>
                            @if ($c->unread > 0)<span class="badge bg-brand-600 text-white" data-inbox-unread>{{ $c->unread }}</span>@endif
                        </span>
                        <span class="mt-1 block text-xs text-navy-500" data-inbox-channel="{{ $c->channel }}">@include('admin.partials.channel-logo', ['channel' => $c->channel]) · {{ $c->last_message_at?->diffForHumans() }}@if ($c->assignee) · {{ $c->assignee->name }}@endif</span>
                    </a>
                </li>
            @empty
                <li class="py-8 text-center text-sm text-navy-500">{{ __('No conversations yet.') }}</li>
            @endforelse
        </ul>
        {{ $conversations->links() }}
    </section>

    <section class="card space-y-4 lg:col-span-2" data-inbox-thread>
        @if (! $conversation)
            <p class="py-16 text-center text-navy-500">{{ __('Choose a conversation.') }}</p>
        @else
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-navy-100 pb-3">
                <div class="min-w-0">
                    <a href="{{ route('admin.inbox') }}" class="mb-1 block text-sm text-brand-700 lg:hidden">{{ __('Back to conversations') }}</a>
                    <h2 class="section-title"><bdi>{{ $conversation->displayName() }}</bdi></h2>
                    @if ($conversation->isWhatsApp())
                        <a href="https://wa.me/{{ $conversation->phone }}" target="_blank" rel="noopener" dir="ltr" class="text-sm text-navy-500">+{{ $conversation->phone }}</a>
                    @else
                        <span class="text-sm text-navy-500">@include('admin.partials.channel-logo', ['channel' => $conversation->channel])</span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="post" action="{{ route('admin.inbox.assign', $conversation) }}" class="flex items-center gap-2">
                        @csrf
                        <label class="sr-only" for="inbox-assign">{{ __('Followed by') }}</label>
                        <select id="inbox-assign" name="assigned_to" class="field min-h-9 w-auto" onchange="this.form.submit()">
                            <option value="">{{ __('Nobody yet') }}</option>
                            @foreach ($team as $member)
                                <option value="{{ $member->id }}" @selected($conversation->assigned_to === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </form>
                    @if ($conversation->botPaused() || $conversation->assigned_to)
                        <form method="post" action="{{ route('admin.inbox.resume-bot', $conversation) }}" data-inbox-resume-bot>
                            @csrf
                            <button class="btn-ghost min-h-9 px-3">{{ __('Give it back to the automatic answer') }}</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('admin.inbox.status', $conversation) }}">
                        @csrf
                        <input type="hidden" name="status" value="{{ $conversation->status === 'open' ? 'closed' : 'open' }}">
                        <button class="btn-ghost min-h-9 px-3">{{ $conversation->status === 'open' ? __('Close the conversation') : __('Reopen the conversation') }}</button>
                    </form>
                    @if ($canAdministerInbox)
                        <form method="post" action="{{ route('admin.inbox.delete', $conversation) }}" onsubmit="return confirm(@js(__('Delete this conversation and all its messages?')))" data-inbox-delete>
                            @csrf
                            <button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="max-h-[32rem] space-y-2 overflow-y-auto" data-inbox-messages>
                @foreach ($messages as $m)
                    <div @class(['flex', 'justify-start' => $m->direction === 'in', 'justify-end' => $m->direction !== 'in'])>
                        <div @class(['max-w-[85%] rounded-2xl px-3 py-2 text-sm',
                            'bg-navy-50 text-navy-900' => $m->direction === 'in',
                            'bg-navy-800 text-white' => $m->direction === 'out' && $m->status !== 'failed',
                            'bg-red-50 text-red-800' => $m->direction === 'out' && $m->status === 'failed',
                            'bg-amber-50 text-amber-900' => $m->direction === 'note']) data-inbox-message="{{ $m->direction }}">
                            @if ($m->direction === 'note')<div class="text-xs font-bold">{{ __('Note for the team') }}</div>@endif
                            <div class="whitespace-pre-line break-words" dir="auto">{{ $m->body }}</div>
                            <div class="mt-1 text-[11px] opacity-70">
                                {{ local_time($m->created_at, 'Y/m/d H:i') }}@if ($m->admin) · {{ $m->admin->name }}@elseif ($m->direction === 'out') · {{ __('Automatic answer') }}@endif
                                @if ($m->direction === 'out') · {{ ['sending' => __('Sending'), 'sent' => __('Sent'), 'delivered' => __('Delivered'), 'read' => __('Read'), 'failed' => __('Not sent')][$m->status] ?? $m->status }}@endif
                            </div>
                            @if ($m->error)<div class="mt-1 text-xs font-semibold">{{ $m->errorText() }}</div>@endif
                        </div>
                    </div>
                @endforeach
            </div>

            @error('body')<p class="rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror

            @if ($conversation->canReply())
                <form method="post" action="{{ route('admin.inbox.reply', $conversation) }}" class="space-y-2" data-inbox-reply>
                    @csrf
                    @if ($canned->isNotEmpty())
                        <label class="sr-only" for="inbox-canned">{{ __('Ready answers') }}</label>
                        <select id="inbox-canned" class="field min-h-9" onchange="if (this.value) { document.getElementById('inbox-body').value = this.value; this.selectedIndex = 0; }">
                            <option value="">{{ __('Ready answers') }}</option>
                            @foreach ($canned as $c)<option value="{{ $c->body }}">{{ $c->title }}</option>@endforeach
                        </select>
                    @endif
                    <label class="sr-only" for="inbox-body">{{ __('Your answer') }}</label>
                    <textarea id="inbox-body" name="body" rows="3" maxlength="4096" required dir="auto" class="field" placeholder="{{ __('Your answer') }}">{{ old('body') }}</textarea>
                    <button class="btn-primary">{{ __('Send on :channel', ['channel' => $conversation->channelLabel()]) }}</button>
                    <span class="text-xs text-navy-500">{{ __('You can answer until :time.', ['time' => local_time($conversation->last_inbound_at->copy()->addHours(\App\Models\PlatformConversation::WINDOW_HOURS), 'Y/m/d H:i')]) }}</span>
                </form>
            @else
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800" data-inbox-window-closed>{{ $conversation->last_inbound_at === null ? __('They have not answered yet. You can write here as soon as they do.') : ($conversation->isWhatsApp() ? __('More than 24 hours passed since their last message. WhatsApp allows only an approved template now, so you can call them or wait until they write again.') : __('More than 24 hours passed since their last message, so you can answer when they write again.')) }}</p>
            @endif

            <details class="rounded-xl border border-navy-100 p-3">
                <summary class="cursor-pointer text-sm font-semibold">{{ __('Add a note for the team') }}</summary>
                <form method="post" action="{{ route('admin.inbox.note', $conversation) }}" class="mt-2 space-y-2">
                    @csrf
                    <label class="sr-only" for="inbox-note">{{ __('Note for the team') }}</label>
                    <textarea id="inbox-note" name="body" rows="2" maxlength="2000" required dir="auto" class="field"></textarea>
                    <button class="btn-ghost min-h-9 px-3">{{ __('Save the note') }}</button>
                </form>
            </details>
        @endif
    </section>
</div>

<section class="card mt-4 space-y-3" data-inbox-canned>
    <h2 class="section-title">{{ __('Ready answers') }}</h2>
    <p class="muted text-sm">{{ __('The automatic answer uses these too, on top of what the site says. Write here what it should know.') }}</p>
    <ul class="divide-y divide-navy-100">
        @foreach ($canned as $c)
            <li class="flex items-start justify-between gap-3 py-2 text-sm">
                <div class="min-w-0"><div class="font-semibold">{{ $c->title }}</div><div class="whitespace-pre-line text-navy-600" dir="auto">{{ $c->body }}</div></div>
                @if ($canAdministerInbox)<form method="post" action="{{ route('admin.inbox.canned.delete', $c) }}">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>@endif
            </li>
        @endforeach
    </ul>
    @if ($canAdministerInbox)
    <form method="post" action="{{ route('admin.inbox.canned') }}" class="grid gap-2 sm:grid-cols-3">
        @csrf
        <label class="sr-only" for="canned-title">{{ __('Title') }}</label>
        <input id="canned-title" name="title" required maxlength="80" class="field min-h-10" placeholder="{{ __('Title') }}">
        <label class="sr-only" for="canned-body">{{ __('The answer') }}</label>
        <textarea id="canned-body" name="body" rows="2" required maxlength="1000" dir="auto" class="field sm:col-span-2" placeholder="{{ __('The answer') }}"></textarea>
        <button class="btn-navy min-h-10 sm:col-start-3">{{ __('Add') }}</button>
    </form>
    @endif
</section>

<script>
    // new messages show by themselves, unless the admin is typing
    (() => {
        const box = document.querySelector('[data-inbox-messages]');
        if (box) { box.scrollTop = box.scrollHeight; }
        setInterval(() => {
            const typing = [...document.querySelectorAll('textarea, input[name=title], input[name=q]')].some((f) => f.value.trim() !== '' || f === document.activeElement);
            if (!document.hidden && !typing) { location.reload(); }
        }, 20000);
    })();
</script>
@endsection
