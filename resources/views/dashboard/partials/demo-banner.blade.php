{{-- While the sample data EgWeb filled in is still there (App\Services\DemoContent): what it is, and the owner's one-click clear. --}}
@if (\App\Services\DemoContent::present())
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" data-demo-banner>
        <span>🧪 {{ __('This is sample data — clear it when you start.') }} <span class="font-normal">{{ __('The orders, guests and other rows marked "(تجربة)" are only there to show you how each page works.') }}</span></span>
        @if (auth()->user()->isOwner())
            <form method="post" action="{{ route('dash.demo.clear') }}" onsubmit="return confirm(@js(__('Clear all the sample data? Only the rows marked "(تجربة)" are removed; your own data stays.')))">
                @csrf
                <input type="hidden" name="confirm" value="1">
                <button class="btn-primary min-h-9 px-3" data-demo-clear>{{ __('Clear the sample data') }}</button>
            </form>
        @endif
    </div>
@endif
