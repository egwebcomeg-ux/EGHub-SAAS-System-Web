@php
    $tile = fn (string $label, string $value) => [$label, $value];
    $sections = [
        [__('Sign-ups'), [
            $tile(__('New'), number_format($report['leads']['new'])),
            $tile(__('Talked to'), number_format($report['leads']['contacted'])),
            $tile(__('Interested'), number_format($report['leads']['interested'])),
            $tile(__('Subscribed'), number_format($report['leads']['won'])),
            $tile(__('Not interested'), number_format($report['leads']['lost'])),
            $tile(__('Overdue follow-ups'), number_format($report['leads']['overdue'])),
        ]],
        [__('Places'), [
            $tile(__('Added'), number_format($report['places']['added'])),
            $tile(__('Contacted'), number_format($report['places']['contacted'])),
            $tile(__('WhatsApp offers'), number_format($report['places']['offers'])),
            $tile(__('Visits booked'), number_format($report['places']['appointments'])),
            $tile(__('Visits done'), number_format($report['places']['visits'])),
            $tile(__('Signed up'), number_format($report['places']['trials'])),
            $tile(__('Subscribed'), number_format($report['places']['won'])),
        ]],
        [__('Phone calls'), [
            $tile(__('All calls'), number_format($report['calls']['total'])),
            $tile(__('Outgoing'), number_format($report['calls']['outgoing'])),
            $tile(__('Incoming'), number_format($report['calls']['incoming'])),
            $tile(__('Talk time'), \App\Models\SalesCall::formatDuration($report['calls']['seconds'])),
            $tile(__('Average call'), \App\Models\SalesCall::formatDuration($report['calls']['average'])),
            $tile(__('Missed'), number_format($report['calls']['missed'])),
            $tile(__('Missed, not returned'), number_format($report['calls']['not_returned'])),
            $tile(__('Waiting for a summary'), number_format($report['calls']['unsummarized'])),
        ]],
    ];
@endphp
@foreach ($sections as [$title, $tiles])
    <section class="report-block mb-4 rounded-2xl border border-navy-100 bg-white p-4" data-report-section>
        <h2 class="section-title mb-3">{{ $title }}</h2>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
            @foreach ($tiles as [$label, $value])
                <div><dt class="text-xs font-semibold text-navy-500">{{ $label }}</dt><dd class="mt-0.5 text-xl font-black tabular-nums text-navy-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>
@endforeach

<section class="report-block mb-4 rounded-2xl border border-navy-100 bg-white p-4" data-report-people>
    <h2 class="section-title mb-3">{{ __('The team') }}</h2>
    <div class="overflow-x-auto">
        <table class="table-base" data-table-scroll>
            <thead><tr>
                <th>{{ __('Team member') }}</th><th>{{ __('Phone calls') }}</th><th>{{ __('Talk time') }}</th><th>{{ __('Missed, not returned') }}</th>
                <th>{{ __('Logged follow-ups') }}</th><th>{{ __('Visits booked') }}</th><th>{{ __('Visits done') }}</th><th>{{ __('Subscribed') }}</th><th>{{ __('Overdue follow-ups') }}</th>
            </tr></thead>
            <tbody class="tabular-nums">
                @forelse ($report['people'] as $row)
                    <tr data-report-person="{{ $row['admin']->id }}">
                        <td class="font-semibold text-navy-900">{{ $row['admin']->name }}</td>
                        <td>{{ number_format($row['calls']) }}</td><td>{{ \App\Models\SalesCall::formatDuration($row['seconds']) }}</td><td>{{ number_format($row['not_returned']) }}</td>
                        <td>{{ number_format($row['logged']) }}</td><td>{{ number_format($row['appointments']) }}</td><td>{{ number_format($row['visits']) }}</td>
                        <td>{{ number_format($row['won']) }}</td><td>{{ number_format($row['overdue']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-6 text-center text-navy-500">{{ __('No sales team members yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="report-block rounded-2xl border border-navy-100 bg-white p-4" data-report-lost>
    <h2 class="section-title mb-3">{{ __('Why they said no') }}</h2>
    @forelse ($report['lost'] as $reason)
        <div class="flex items-center justify-between border-b border-navy-50 py-2 text-sm last:border-0"><span>{{ $reason['label'] }}</span><span class="font-bold tabular-nums">{{ number_format($reason['count']) }}</span></div>
    @empty
        <p class="text-sm text-navy-500">{{ __('Nobody said no in this period.') }}</p>
    @endforelse
</section>
<p class="mt-3 text-xs text-navy-500">{{ __('Calls count from the day a phone was linked. Overdue follow-ups are as of today, not of the period.') }}</p>
