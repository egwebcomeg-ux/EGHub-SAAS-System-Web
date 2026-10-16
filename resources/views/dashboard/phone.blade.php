@extends('layouts.dashboard')
@section('title', __('Confirm your number on WhatsApp'))

@section('content')
<h1 class="page-title mb-1">{{ __('Confirm your number on WhatsApp') }}</h1>
<p class="muted mb-4">{{ __('One ready message from your phone, no codes to type. Your account is confirmed the moment it arrives.') }}</p>

<div class="grid max-w-5xl gap-4 lg:grid-cols-5" data-phone-verify data-status-url="{{ route('dash.phone.status') }}" data-verified="{{ $user->hasVerifiedPhone() ? '1' : '0' }}">
    <section class="card space-y-4 lg:col-span-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-navy-500">{{ __('Your mobile') }}</div>
                <div class="text-xl font-extrabold" dir="ltr">{{ $user->phone ?? '—' }}</div>
            </div>
            <span data-badge-pending @class(['badge bg-amber-100 text-amber-800', 'hidden' => $user->hasVerifiedPhone()])>{{ __('Not confirmed yet') }}</span>
            <span data-badge-done @class(['badge bg-emerald-100 text-emerald-800', 'hidden' => ! $user->hasVerifiedPhone()])>{{ __('Phone confirmed') }}</span>
        </div>

        <div data-done @class(['rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800', 'hidden' => ! $user->hasVerifiedPhone()])>
            ✓ {{ __('Your number is confirmed. Thank you!') }}
            <a href="{{ route('dash.home') }}" class="ms-2 underline">{{ __('Back to home') }}</a>
        </div>

        <div data-pending @class(['space-y-4', 'hidden' => $user->hasVerifiedPhone()])>
            @if (! $user->phone)
                <p class="text-sm text-navy-700">{{ __('Add your mobile number below to confirm it.') }}</p>
            @elseif (! $link)
                <p class="rounded-xl bg-navy-50 p-4 text-sm text-navy-700">{{ __('Phone confirmation is not available at the moment. You can keep using your dashboard as usual.') }}</p>
            @else
                <ol class="list-inside list-decimal space-y-1 text-sm text-navy-700">
                    <li>{{ __('Tap the button: WhatsApp opens with the message ready.') }}</li>
                    <li>{{ __('Send it from the phone that has the number :phone.', ['phone' => $user->phone]) }}</li>
                    <li>{{ __('This page turns green by itself once the message arrives.') }}</li>
                </ol>
                <a href="{{ $link }}" target="_blank" rel="noopener" class="btn-primary min-h-12 w-full text-base sm:w-auto" data-wa-link>
                    <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4 5.3 5.3 0 0 0 3.2.7 2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg>
                    {{ __('Open WhatsApp and send') }}
                </a>
                <div class="rounded-xl bg-navy-50 p-3 text-sm text-navy-700">
                    <div class="mb-1">{{ __('Or send this text yourself to :number:', ['number' => '+'.$number]) }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="rounded-lg bg-white px-3 py-1.5 text-base font-bold tracking-widest" dir="ltr">{{ $message }}</code>
                        <button type="button" class="btn-ghost min-h-9 px-3" data-copy="{{ $message }}">{{ __('Copy') }}</button>
                    </div>
                </div>
                <p class="flex items-center gap-2 text-sm text-navy-500" data-waiting>
                    <span class="inline-block size-2 animate-pulse rounded-full bg-amber-500"></span>{{ __('Waiting for your message…') }}
                </p>
            @endif
        </div>
    </section>

    <div class="space-y-4 lg:col-span-2">
        <form method="post" action="{{ route('dash.phone.update') }}" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('I changed my number') }}</h2>
            <p class="text-sm text-navy-500">{{ __('Enter the Egyptian mobile you use on WhatsApp. You get a new message to send.') }}</p>
            <div>
                <label class="field-label" for="phone">{{ __('New mobile number') }}</label>
                <input class="field" id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx">
            </div>
            <button class="btn-ghost w-full">{{ __('Update number') }}</button>
        </form>
        @if (! $user->hasVerifiedPhone())
            <a href="{{ route('dash.home') }}" class="block text-center text-sm text-navy-500 hover:text-navy-700">{{ __('Skip for now — you can confirm later from the dashboard.') }}</a>
        @endif
    </div>
</div>

<script>
    (() => {
        const root = document.querySelector('[data-phone-verify]');
        document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
        }));
        if (root.dataset.verified === '1') return;
        const done = () => {
            root.querySelector('[data-pending]').classList.add('hidden');
            root.querySelector('[data-badge-pending]').classList.add('hidden');
            root.querySelector('[data-done]').classList.remove('hidden');
            root.querySelector('[data-badge-done]').classList.remove('hidden');
            root.dataset.verified = '1';
        };
        // every 5s for up to 15 minutes, while the tab is visible
        let tries = 0;
        const timer = setInterval(async () => {
            if (document.hidden) return;
            if (++tries > 180) { clearInterval(timer); return; }
            try {
                const res = await fetch(root.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.verified) { clearInterval(timer); done(); }
            } catch (e) { /* offline: try again next tick */ }
        }, 5000);
    })();
</script>
@endsection
