{{-- the top of the admin home: today's work of this member of the team (App\Services\AdminWorkspace) --}}
@if ($seat)
    @if ($seat['seat'] === 'field')
        <section class="card mb-4" data-workspace="field">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="section-title">{{ __('Your visits today') }}</h2>
                    @if ($seat['late'] > 0)
                        <p class="text-sm font-semibold text-red-700">{{ __(':n visits from earlier days still need a report', ['n' => $seat['late']]) }}</p>
                    @endif
                </div>
                <span class="text-3xl font-black text-navy-900">{{ $seat['visits']->count() }}</span>
            </div>
            @if ($seat['visits']->isNotEmpty())
                <ul class="mb-3 divide-y divide-navy-50">
                    @foreach ($seat['visits'] as $visit)
                        <li class="flex items-center justify-between gap-3 py-2.5" data-today-visit="{{ $visit->id }}">
                            <div class="min-w-0">
                                <div class="truncate font-bold text-navy-900">{{ $visit->prospect?->name }}</div>
                                <div class="truncate text-xs text-navy-500">{{ $visit->address ?: $visit->prospect?->area }}</div>
                            </div>
                            <span class="shrink-0 text-sm font-black text-brand-700" dir="ltr">{{ local_time($visit->scheduled_at, 'h:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mb-3 text-sm text-navy-500">{{ __('No visits booked for today. Use the suggested places on your visits page.') }}</p>
            @endif
            <div class="grid gap-2 sm:grid-cols-2">
                <a href="{{ route('admin.crm.visits') }}" class="btn-primary min-h-14 justify-center text-base">{{ __('Open my visits') }}</a>
                <a href="{{ route('admin.crm.prospects') }}" class="btn-ghost min-h-14 justify-center text-base">{{ __('My places') }}</a>
            </div>
        </section>
    @else
        <section class="card mb-4" data-workspace="caller">
            <div class="grid gap-3 sm:grid-cols-3">
                <a href="{{ route('admin.crm.prospects', ['mine' => '1', 'sort' => 'follow_up']) }}" class="rounded-xl border border-navy-100 p-3 transition hover:border-navy-300" data-seat-count="follow_ups">
                    <div class="text-xs font-semibold text-navy-500">{{ __('Calls due today or late') }}</div>
                    <div class="text-3xl font-black {{ $seat['followUps'] > 0 ? 'text-red-700' : 'text-navy-300' }}">{{ $seat['followUps'] }}</div>
                </a>
                <div class="rounded-xl border border-navy-100 p-3">
                    <div class="text-xs font-semibold text-navy-500">{{ __('Calls today') }}</div>
                    <div class="text-3xl font-black text-navy-900">{{ $seat['card']['today']['calls'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-navy-100 p-3">
                    <div class="text-xs font-semibold text-navy-500">{{ __('Visits you booked ahead') }}</div>
                    <div class="text-3xl font-black text-navy-900">{{ $seat['booked']->count() }}</div>
                </div>
            </div>
            @if ($seat['booked']->isNotEmpty())
                <ul class="mt-3 divide-y divide-navy-50 text-sm">
                    @foreach ($seat['booked'] as $visit)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="truncate font-semibold">{{ $visit->prospect?->name }} <span class="font-normal text-navy-500">· {{ $visit->field?->name ?? __('No field rep yet') }}</span></span>
                            <span class="shrink-0 text-navy-600" dir="ltr">{{ local_time($visit->scheduled_at, 'm/d h:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('admin.crm.prospects') }}" class="btn-primary">{{ __('Places to call') }}</a>
                @if (auth('admin')->user()->allows('crm.manage'))
                    <a href="{{ route('admin.crm') }}" class="btn-ghost">{{ __('Restaurants to follow up') }}</a>
                @endif
            </div>
        </section>
    @endif
    @include('admin.crm.partials.progress', ['card' => $seat['card']])
@endif

@if ($attention)
    <section class="card mb-4" data-attention>
        <h2 class="section-title mb-3">{{ __('Needs attention') }}</h2>
        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-4">
            @foreach ($attention as [$key, $label, $count, $href, $tone])
                <a href="{{ $href }}" class="rounded-xl border border-navy-100 p-3 transition hover:border-navy-300" data-attention-item="{{ $key }}">
                    <div class="text-xs font-semibold text-navy-500">{{ $label }}</div>
                    <div class="text-2xl font-black {{ $tone }}">{{ number_format($count) }}</div>
                </a>
            @endforeach
        </div>
    </section>
@endif

@if ($support && ($support['requests']->isNotEmpty() || $support['restaurants']->isNotEmpty()))
    <div class="mb-4 grid gap-4 lg:grid-cols-2" data-workspace="support">
        @if ($support['requests']->isNotEmpty())
            <section class="card">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="section-title">{{ __('Service requests to answer') }}</h2>
                    <a href="{{ route('admin.services') }}" class="text-sm font-semibold text-navy-600 hover:underline">{{ __('All requests') }}</a>
                </div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @foreach ($support['requests'] as $r)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate"><span class="font-semibold">{{ $r->restaurant ?: $r->name }}</span> · {{ $r->serviceName() }}</span>
                            <span class="shrink-0 text-xs text-navy-500">{{ local_time($r->created_at, 'm/d h:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        @if ($support['restaurants']->isNotEmpty())
            <section class="card">
                <h2 class="section-title mb-2">{{ __('New restaurants this week') }}</h2>
                <ul class="divide-y divide-navy-50 text-sm">
                    @foreach ($support['restaurants'] as ['tenant' => $t, 'verified' => $verified])
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('admin.tenants.show', $t) }}" class="min-w-0 truncate font-semibold hover:text-brand-700">{{ $t->name }}</a>
                            <span class="badge shrink-0 {{ $verified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $verified ? __('Mobile confirmed') : __('Mobile not confirmed') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endif

@if ($content)
    <div class="mb-4 grid gap-4 lg:grid-cols-2" data-workspace="content">
        @if (auth('admin')->user()->allows('content.manage'))
            <section class="card">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="section-title">{{ __('Blog') }}</h2>
                    <a href="{{ route('admin.blog.create') }}" class="btn-primary">{{ __('New article') }}</a>
                </div>
                <div class="mb-2 grid grid-cols-2 gap-2">
                    <a href="{{ route('admin.blog') }}" class="rounded-xl border border-navy-100 p-3 hover:border-navy-300" data-content-count="drafts">
                        <div class="text-xs font-semibold text-navy-500">{{ __('Drafts') }}</div>
                        <div class="text-2xl font-black">{{ $content['drafts'] }}</div>
                    </a>
                    <a href="{{ route('admin.blog') }}" class="rounded-xl border border-navy-100 p-3 hover:border-navy-300" data-content-count="scheduled">
                        <div class="text-xs font-semibold text-navy-500">{{ __('Scheduled') }}</div>
                        <div class="text-2xl font-black">{{ $content['scheduled'] }}</div>
                    </a>
                </div>
                @foreach ($content['latestDrafts'] as $post)
                    <a href="{{ route('admin.blog.edit', $post) }}" class="block truncate py-1 text-sm font-semibold text-navy-800 hover:text-brand-700">{{ $post->displayTitle() }}</a>
                @endforeach
            </section>
        @endif
        @if ($content['questions']->isNotEmpty())
            <section class="card">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="section-title">{{ __('Latest questions to the assistant') }}</h2>
                    <a href="{{ route('admin.assistant') }}" class="text-sm font-semibold text-navy-600 hover:underline">{{ __('All questions') }}</a>
                </div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @foreach ($content['questions'] as $m)
                        <li class="flex items-start justify-between gap-3 py-2">
                            <span class="min-w-0 truncate">{{ $m->question }}</span>
                            @if ($m->handoff)<span class="badge shrink-0 bg-brand-100 text-brand-700">{{ __('WhatsApp') }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endif
