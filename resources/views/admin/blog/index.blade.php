@extends('layouts.admin')
@section('title', __('Blog') . ' · EgHub Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('Blog') }}</h1>
        <p class="text-sm text-navy-500">{{ __('Articles for cafe and restaurant owners on eghub.app/blog. Published ones also go out on the RSS feed and the sitemap.') }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('blog') }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Open the blog') }}</a>
        <a href="{{ route('admin.blog.create') }}" class="btn-primary">+ {{ __('New article') }}</a>
    </div>
</div>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Status') }}</th><th>{{ __('Published') }}</th><th>{{ __('Author') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($posts as $post)
            <tr>
                <td>
                    <a href="{{ route('admin.blog.edit', $post) }}" class="font-semibold text-brand-700 hover:underline">{{ $post->title }}</a>
                    @if ($post->title_en)<div class="text-xs text-navy-500" dir="ltr">{{ $post->title_en }}</div>@endif
                    <div class="text-xs text-navy-400" dir="ltr">/blog/{{ $post->slug }}</div>
                </td>
                <td>
                    <span class="badge {{ $post->isLive() ? 'bg-emerald-50 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $post->isLive() ? __('Published') : ($post->is_published ? __('Scheduled') : __('Draft')) }}</span>
                </td>
                <td class="whitespace-nowrap">{{ $post->published_at ? local_time($post->published_at, 'Y/m/d') : '—' }}</td>
                <td>{{ $post->author?->name ?? '—' }}</td>
                <td>
                    <div class="flex justify-end gap-2">
                        <form method="post" action="{{ route('admin.blog.publish', $post) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $post->is_published ? __('Hide') : __('Publish') }}</button></form>
                        <form method="post" action="{{ route('admin.blog.delete', $post) }}" onsubmit="return confirm(@js(__('Delete this article?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-navy-500">{{ __('No articles yet. Write the first one.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $posts->links() }}</div>
@endsection
