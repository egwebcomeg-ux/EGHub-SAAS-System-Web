<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** The marketing blog (eghub.app/blog): write Markdown articles, upload a cover, publish or hide them. */
class BlogController extends Controller
{
    public function index(): View
    {
        return view('admin.blog.index', [
            'posts' => Post::query()->with('author')->orderByDesc('published_at')->orderByDesc('id')->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('admin.blog.form', ['post' => new Post(['is_published' => false])]);
    }

    public function store(Request $request, ImageService $images): RedirectResponse
    {
        $post = new Post;
        $this->fill($post, $this->validated($request, null), $request, $images);
        $post->author_id = $request->user('admin')->id;
        $post->save();
        $this->flush();

        return redirect()->route('admin.blog.edit', $post)->with('ok', __('Article saved.'));
    }

    public function edit(Post $post): View
    {
        return view('admin.blog.form', ['post' => $post]);
    }

    public function update(Request $request, Post $post, ImageService $images): RedirectResponse
    {
        $this->fill($post, $this->validated($request, $post->id), $request, $images);
        $post->save();
        $this->flush();

        return back()->with('ok', __('Article saved.'));
    }

    /** Publish now (keeping an earlier date when one exists) or take the article offline. */
    public function publish(Post $post): RedirectResponse
    {
        $post->update($post->is_published
            ? ['is_published' => false]
            : ['is_published' => true, 'published_at' => $post->published_at ?? now()]);
        $this->flush();

        return back()->with('ok', $post->is_published ? __('Article published.') : __('Article hidden.'));
    }

    public function destroy(Post $post, ImageService $images): RedirectResponse
    {
        $images->delete($post->cover);
        $post->delete();
        $this->flush();

        return redirect()->route('admin.blog')->with('ok', __('Article deleted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        return $request->validate([
            'title' => 'required|string|max:160',
            'title_en' => 'nullable|string|max:160',
            'slug' => 'nullable|string|max:120|regex:/^[A-Za-z0-9-]+$/',
            'excerpt' => 'nullable|string|max:300',
            'excerpt_en' => 'nullable|string|max:300',
            'body' => 'required|string|max:60000',
            'body_en' => 'nullable|string|max:60000',
            'meta_description' => 'nullable|string|max:160',
            'meta_description_en' => 'nullable|string|max:160',
            'published_at' => 'nullable|date',
            'cover' => 'nullable|image|max:5120',
            'remove_cover' => 'nullable|boolean',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fill(Post $post, array $data, Request $request, ImageService $images): void
    {
        $post->fill([
            'title' => $data['title'], 'title_en' => $data['title_en'] ?? '',
            'excerpt' => $data['excerpt'] ?? '', 'excerpt_en' => $data['excerpt_en'] ?? '',
            'body' => $data['body'], 'body_en' => $data['body_en'] ?? null,
            'meta_description' => $data['meta_description'] ?? '', 'meta_description_en' => $data['meta_description_en'] ?? '',
            'is_published' => $request->boolean('is_published'),
        ]);
        $post->slug = Post::slugFor($data['title'], $data['slug'] ?? ($post->exists ? $post->slug : null), $post->id);
        if (! empty($data['published_at'])) {
            $post->published_at = local_input($data['published_at']);
        } elseif ($post->is_published && ! $post->published_at) {
            $post->published_at = now();
        }
        if ($request->boolean('remove_cover') && $post->cover) {
            $images->delete($post->cover);
            $post->cover = null;
        }
        if ($request->hasFile('cover')) {
            $images->delete($post->cover);
            $post->cover = $images->store($request->file('cover'), 'post');
        }
    }

    private function flush(): void
    {
        Cache::forget('seo.sitemap.'.platform_url(null, ''));
    }
}
