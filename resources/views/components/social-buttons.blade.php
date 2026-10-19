{{--
    "Continue with Google / Facebook" above a login or signup form, then an "or" divider. Each button shows only
    when its provider's keys are set (App\Services\Auth\SocialProviders); with none set nothing is rendered.
    Both always start on the dashboard host (dash.social.redirect), where the OAuth state lives.
--}}
@php($providers = \App\Services\Auth\SocialProviders::enabled())
@if ($providers)
    <div class="grid gap-2.5" data-social-buttons>
        @foreach ($providers as $provider)
            @if ($provider === 'google')
                <a href="{{ route('dash.social.redirect', 'google') }}" data-social="google"
                   class="flex min-h-11 items-center justify-center gap-3 rounded-xl border border-[#dadce0] bg-white px-4 text-sm font-semibold text-[#1f1f1f] shadow-sm transition hover:bg-[#f8f9fa] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4285F4]">
                    <svg class="size-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    {{ __('Continue with Google') }}
                </a>
            @else
                <a href="{{ route('dash.social.redirect', 'facebook') }}" data-social="facebook"
                   class="flex min-h-11 items-center justify-center gap-3 rounded-xl bg-[#1877F2] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#166fe5] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1877F2]">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#fff" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
                    </svg>
                    {{ __('Continue with Facebook') }}
                </a>
            @endif
        @endforeach
    </div>
    <div class="my-5 flex items-center gap-3 text-xs font-semibold text-navy-400" role="separator">
        <span class="h-px flex-1 bg-navy-100"></span>{{ __('or') }}<span class="h-px flex-1 bg-navy-100"></span>
    </div>
@endif
