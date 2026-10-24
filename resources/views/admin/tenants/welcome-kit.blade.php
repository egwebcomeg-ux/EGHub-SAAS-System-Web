{{--
    The owner's welcome kit (App\Services\WelcomeKit) on the admin restaurant page: the signed welcome page, copy
    buttons for the link and the sign-in details, "send it on WhatsApp" (the link only, never the password) and a
    new kit (new temporary password, older links stop working). Plus how many sample rows the place still holds.
--}}
@php
    $kitFresh = session('kit_created');
@endphp
<section @class(['card mb-4', 'ring-2 ring-brand-500' => $kitFresh]) id="welcome-kit" data-welcome-kit>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="section-title">{{ __('Welcome kit') }}</h2>
            <p class="mt-1 text-sm text-navy-500">{{ __('The owner\'s printable welcome page: menu link and QR, sign-in details, trial and first steps.') }}</p>
        </div>
        @if ($demoRows > 0)
            <span class="badge bg-amber-100 text-amber-800" data-demo-rows>{{ __(':count sample rows', ['count' => $demoRows]) }}</span>
        @endif
    </div>

    @if ($welcomeKit && $welcomeKit['url'])
        @php
            $kitLines = array_filter([
                __('Dashboard') . ': ' . platform_url('app', '/login'),
                __('Sign-in') . ': ' . $welcomeKit['login'],
                $welcomeKit['owner']->phone && $welcomeKit['owner']->phone !== $welcomeKit['login'] ? __('or mobile') . ': ' . $welcomeKit['owner']->phone : null,
                $welcomeKit['password'] ? __('Temporary password') . ': ' . $welcomeKit['password'] : null,
            ]);
        @endphp
        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <div class="rounded-2xl border border-navy-100 p-3">
                <div class="text-xs font-bold uppercase text-navy-500">{{ __('Welcome page link') }}</div>
                <div class="mt-1 break-all text-sm" dir="ltr" data-kit-url>{{ $welcomeKit['url'] }}</div>
                <div class="mt-1 text-xs text-navy-500">{{ __('Works until :date.', ['date' => local_time($welcomeKit['expires_at'], 'Y/m/d')]) }}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ $welcomeKit['url'] }}" target="_blank" rel="noopener noreferrer" class="btn-primary min-h-10 px-3" data-kit-open>{{ __('Open / print') }}</a>
                    <button type="button" class="btn-ghost min-h-10 px-3" data-copy="{{ $welcomeKit['url'] }}">{{ __('Copy link') }}</button>
                    @if ($welcomeKit['whatsapp'])
                        <a href="{{ $welcomeKit['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="btn-ghost min-h-10 px-3 text-emerald-700" data-kit-whatsapp>{{ __('Send it on WhatsApp') }}</a>
                    @else
                        <span class="self-center text-xs text-navy-500">{{ __('Add the owner\'s mobile to send it on WhatsApp.') }}</span>
                    @endif
                </div>
            </div>
            <div class="rounded-2xl border border-navy-100 p-3">
                <div class="text-xs font-bold uppercase text-navy-500">{{ __('Sign-in details') }}</div>
                <dl class="mt-1 space-y-1 text-sm">
                    <div class="flex flex-wrap gap-2"><dt class="text-navy-500">{{ __('Sign-in') }}</dt><dd dir="ltr" class="break-all font-semibold">{{ $welcomeKit['login'] }}</dd>
                        @if ($welcomeKit['placeholder'])<span class="badge bg-amber-100 text-amber-800" data-placeholder-email>{{ __('Placeholder email, never mailed') }}</span>@endif</div>
                    @if ($welcomeKit['owner']->phone)
                        <div class="flex flex-wrap gap-2"><dt class="text-navy-500">{{ __('or mobile') }}</dt><dd dir="ltr" class="font-semibold">{{ $welcomeKit['owner']->phone }}</dd></div>
                    @endif
                    <div class="flex flex-wrap items-center gap-2"><dt class="text-navy-500">{{ __('Temporary password') }}</dt>
                        @if ($welcomeKit['password'])
                            <dd><code class="rounded-lg bg-navy-50 px-2 py-0.5 font-bold tracking-wider" dir="ltr" data-kit-password>{{ $welcomeKit['password'] }}</code></dd>
                        @else
                            <dd class="font-semibold text-emerald-700" data-kit-password-changed>✓ {{ __('The owner changed it') }}</dd>
                        @endif
                    </div>
                </dl>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn-ghost min-h-10 px-3" data-copy="{{ implode("\n", $kitLines) }}" data-kit-copy-credentials>{{ __('Copy sign-in details') }}</button>
                </div>
            </div>
        </div>
    @elseif ($welcomeKit)
        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{{ __('The welcome page link expired. Make a new kit to send a fresh one.') }}</p>
    @else
        <p class="mt-3 text-sm text-navy-500">{{ __('No welcome kit yet. Making one sets a new temporary password for the owner.') }}</p>
    @endif

    @if ($canManage)
        <form method="post" action="{{ route('admin.tenants.welcome-kit', $tenant) }}" class="mt-4 flex flex-wrap items-center gap-3 border-t border-navy-100 pt-3"
              onsubmit="return confirm(@js(__('This sets a new temporary password for the owner and older welcome links stop working. Go on?')))">
            @csrf
            <button class="btn-ghost min-h-10 px-3">{{ $welcomeKit ? __('New kit with a new password') : __('Make a welcome kit') }}</button>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="email" value="1" checked class="size-4 accent-brand-600"> {{ __('Email it to the owner (real addresses only)') }}</label>
            @error('welcome_kit')<span class="field-error">{{ $message }}</span>@enderror
        </form>
    @endif
</section>
<script>
    document.querySelectorAll('[data-welcome-kit] [data-copy]').forEach((button) => button.addEventListener('click', () => {
        const label = button.textContent;
        navigator.clipboard?.writeText(button.dataset.copy).then(() => {
            button.textContent = @js(__('Copied'));
            setTimeout(() => { button.textContent = label; }, 1500);
        });
    }));
</script>
