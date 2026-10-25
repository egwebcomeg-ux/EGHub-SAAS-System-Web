{{-- apps: the EgHub apps as tiles, the TV player marked available and the others coming soon (App\Support\EgHubApps) --}}
@php
    $tiles = collect(\App\Support\EgHubApps::all())->values();
@endphp
@foreach ($tiles as $i => $app)
    @php
        $left = 10 + ($i % 2) * 212;
        $top = 8 + intdiv($i, 2) * 166;
        $available = $app['status'] === \App\Support\EgHubApps::AVAILABLE;
    @endphp
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 196px; height: 150px; padding: 18px; border-radius: 24px">
        <span class="ic big"><x-landing.icon :name="$app['icon']" class="i" /></span>
        <div class="t" style="margin-top: 10px; font-size: 17px; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ $app['name'] }}</div>
        <div style="margin-top: 6px; display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: 14px; font-weight: 800; {{ $available ? 'background:#ECFDF5;color:#047857' : 'background:#FFFBEB;color:#92400E' }}">{{ \App\Support\EgHubApps::statusLabel($app['status']) }}</div>
    </div>
@endforeach
