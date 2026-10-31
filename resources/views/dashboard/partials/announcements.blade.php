{{-- EgWeb's announcements to this restaurant (App\Models\Announcement::visibleTo): live, aimed at all restaurants or
     at this one's plan, and not hidden by this user. Each one has its own "hide" for the signed-in user only. --}}
@foreach (\App\Models\Announcement::visibleTo(auth()->user(), tenant()) as $announcement)
    <div @class(['mb-4 flex flex-wrap items-start justify-between gap-3 rounded-xl border px-4 py-3 text-sm',
        'border-amber-200 bg-amber-50 text-amber-900' => $announcement->level === 'warning',
        'border-navy-100 bg-white text-navy-800' => $announcement->level !== 'warning'])
        role="status" data-announcement="{{ $announcement->id }}">
        <div class="min-w-0 flex-1">
            <div class="font-bold">{{ $announcement->title }}</div>
            <p class="mt-0.5 whitespace-pre-line">{{ $announcement->body }}</p>
            @if ($announcement->link)
                <a href="{{ $announcement->link }}" class="mt-1 inline-block font-semibold underline" target="_blank" rel="noopener noreferrer">{{ __('Read more') }}</a>
            @endif
        </div>
        <form method="post" action="{{ route('dash.announcements.dismiss', $announcement->id) }}">
            @csrf
            <button class="grid size-9 place-items-center rounded-lg hover:bg-black/5" aria-label="{{ __('Hide this announcement') }}" title="{{ __('Hide this announcement') }}" data-announcement-dismiss>
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </form>
    </div>
@endforeach
