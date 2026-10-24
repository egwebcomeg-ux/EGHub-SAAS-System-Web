{{--
    EgWeb support signed in from the admin (App\Services\SupportAccess): a fixed bar on every dashboard page
    (hidden when printing), with the way back to the admin. Never on other hosts: their sessions are their own.
--}}
@if (\App\Services\SupportAccess::active(request()) && auth('web')->check() && tenant())
    @php
        $supportTenant = tenant();
        $supportMinutes = \App\Services\SupportAccess::minutesLeft(request());
    @endphp
    <div class="h-28 sm:h-16 print:hidden" aria-hidden="true"></div>
    <div class="fixed inset-x-0 bottom-0 z-[200] border-t-4 border-amber-400 bg-red-700 text-white shadow-2xl print:hidden" role="alert" data-support-banner>
        <div class="mx-auto flex max-w-[100rem] flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6">
            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-amber-400 text-red-900" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4v5c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V7z"/><path d="M12 8v4M12 16h.01"/></svg>
            </span>
            <div class="min-w-0 flex-1 text-sm leading-6">
                <div class="font-bold">{{ __('You are signed in as :user at :restaurant from the admin', ['user' => auth('web')->user()->name, 'restaurant' => $supportTenant->name]) }} — <span dir="ltr">{{ session(\App\Services\SupportAccess::ADMIN_EMAIL) }}</span></div>
                <div class="text-xs text-red-100">
                    {{ __('Ends by itself in :minutes minutes. Password, two-factor and payments are not available.', ['minutes' => $supportMinutes]) }}
                    @if ($supportTenant->isSuspended())
                        <span class="ms-1 inline-block rounded bg-amber-400 px-1.5 py-0.5 font-bold text-red-900" data-support-suspended>{{ __('This restaurant is suspended: its menu is offline for guests.') }}</span>
                    @endif
                </div>
            </div>
            <form method="post" action="{{ route('dash.support-access.exit') }}" class="shrink-0">
                @csrf
                <button class="min-h-10 rounded-xl bg-white px-4 text-sm font-bold text-red-800 hover:bg-red-50">{{ __('Leave and go back to the admin') }}</button>
            </form>
        </div>
    </div>
@endif
