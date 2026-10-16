@extends('layouts.admin')
@section('title', ($post->exists ? __('Edit article') : __('New article')) . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ $post->exists ? __('Edit article') : __('New article') }}</h1>
    <div class="flex gap-2">
        @if ($post->exists && $post->isLive())<a href="{{ route('blog.show', $post->slug) }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('View') }}</a>@endif
        <a href="{{ route('admin.blog') }}" class="btn-ghost">{{ __('All articles') }}</a>
    </div>
</div>

@if ($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<form method="post" action="{{ $post->exists ? route('admin.blog.update', $post) : route('admin.blog.store') }}" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-3">
    @csrf
    <div class="space-y-4 lg:col-span-2">
        <div class="card space-y-3">
            <label class="block"><span class="field-label">{{ __('Title (Arabic)') }}</span><input class="field text-lg font-bold" name="title" value="{{ old('title', $post->title) }}" maxlength="160" required></label>
            <label class="block"><span class="field-label">{{ __('Title (English, optional)') }}</span><input class="field" name="title_en" value="{{ old('title_en', $post->title_en) }}" maxlength="160" dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Excerpt (Arabic)') }}</span><textarea class="field" name="excerpt" rows="2" maxlength="300">{{ old('excerpt', $post->excerpt) }}</textarea></label>
            <label class="block"><span class="field-label">{{ __('Excerpt (English, optional)') }}</span><textarea class="field" name="excerpt_en" rows="2" maxlength="300" dir="ltr">{{ old('excerpt_en', $post->excerpt_en) }}</textarea></label>
        </div>
        <div class="card space-y-3">
            <label class="block"><span class="field-label">{{ __('Body (Arabic, Markdown)') }}</span>
                <textarea class="field min-h-[420px] font-mono text-sm leading-relaxed" name="body" required>{{ old('body', $post->body) }}</textarea></label>
            <p class="text-xs text-navy-500">{{ __('Markdown: # heading, **bold**, - list, > quote, [link](https://…), ![alt](image URL). Raw HTML is removed.') }}</p>
            <details>
                <summary class="cursor-pointer text-sm font-semibold">{{ __('English body (optional)') }}</summary>
                <textarea class="field mt-2 min-h-[300px] font-mono text-sm leading-relaxed" name="body_en" dir="ltr">{{ old('body_en', $post->body_en) }}</textarea>
            </details>
        </div>
    </div>

    <div class="space-y-4">
        <div class="card space-y-3">
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="is_published" value="1" class="size-4 accent-brand-600" @checked(old('is_published', $post->is_published))> {{ __('Published') }}</label>
            <label class="block"><span class="field-label">{{ __('Publish date (optional)') }}</span>
                <input class="field" type="datetime-local" name="published_at" value="{{ old('published_at', $post->published_at?->format('Y-m-d\TH:i')) }}" dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Slug (Latin letters, optional)') }}</span>
                <input class="field" name="slug" value="{{ old('slug', $post->slug) }}" maxlength="120" dir="ltr" placeholder="qr-menu-mistakes"></label>
            <p class="text-xs text-navy-500">{{ __('Leave the slug empty to make one from the title (Arabic titles get post-N).') }}</p>
            <button class="btn-primary w-full">{{ __('Save') }}</button>
        </div>
        <div class="card space-y-3">
            <span class="field-label">{{ __('Cover image') }}</span>
            @if ($post->cover)
                <img src="/storage/{{ $post->cover }}" alt="" class="aspect-video w-full rounded-xl object-cover">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_cover" value="1" class="size-4 accent-brand-600"> {{ __('Remove the cover') }}</label>
            @endif
            <input class="field" type="file" name="cover" accept="image/*">
            <p class="text-xs text-navy-500">{{ __('JPG, PNG or WebP up to 5 MB; shown at 16:9 and saved as WebP.') }}</p>
        </div>
        <div class="card space-y-3">
            <span class="field-label">{{ __('Search snippet') }}</span>
            <textarea class="field" name="meta_description" rows="3" maxlength="160" placeholder="{{ __('Up to 160 characters; the excerpt is used when empty.') }}">{{ old('meta_description', $post->meta_description) }}</textarea>
            <textarea class="field" name="meta_description_en" rows="2" maxlength="160" dir="ltr" placeholder="English">{{ old('meta_description_en', $post->meta_description_en) }}</textarea>
        </div>
    </div>
</form>
@endsection
