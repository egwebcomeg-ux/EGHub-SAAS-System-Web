{{--
    The video tutorials of the current page (App\Support\Tutorials): each one's window, the video with its steps. Nothing
    shows when the page has no recorded tutorial. $tutorials: the page's tutorials; $inline: a button per tutorial in the
    page (otherwise the dashboard's top bar carries the "▶ شرح بالفيديو" button that opens the first one).
--}}
@php($tutorials = $tutorials ?? (auth()->check() ? \App\Support\Tutorials::forRoute(request()->route()?->getName(), auth()->user()) : []))
@if ($tutorials)
    @foreach ($tutorials as $tutorial)
        @if ($inline ?? false)
            <button type="button" class="btn-ghost" onclick="document.getElementById('tutorial-{{ $tutorial['key'] }}').showModal()" data-tutorial-open="{{ $tutorial['key'] }}">▶ {{ $tutorial['title'] }}</button>
        @endif
        <dialog id="tutorial-{{ $tutorial['key'] }}" class="m-auto w-[min(56rem,calc(100vw-2rem))] rounded-2xl p-0 backdrop:bg-navy-900/60" data-tutorial="{{ $tutorial['key'] }}"
                onclose="this.querySelector('video')?.pause()">
            <div class="flex items-center justify-between gap-3 border-b border-navy-100 px-4 py-3">
                <h2 class="font-extrabold">{{ $tutorial['title'] }}</h2>
                <form method="dialog"><button class="btn-ghost min-h-9 px-3" aria-label="{{ __('Close') }}">✕</button></form>
            </div>
            <video class="aspect-video w-full bg-navy-900" src="{{ $tutorial['video'] }}" controls preload="none" playsinline></video>
            <ol class="list-decimal space-y-1 px-8 py-4 text-sm text-navy-700">
                @foreach ($tutorial['steps'] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </dialog>
    @endforeach
@endif
