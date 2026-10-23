<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Audit;
use App\Services\KnowledgeBase;
use App\Support\BusinessType;
use App\Support\KnowledgeBaseTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The knowledge base ("الأسئلة والأجوبة"): the owner or a manager writes the answers the team gives every day, each
 * one for the staff only or for the guests too (the about page of the menu). The call center, the POS and the order
 * board read them from feed() in a search drawer (App\Services\KnowledgeBase, cached per restaurant).
 */
class KnowledgeBaseController extends Controller
{
    /** list filter => what it shows */
    public const FILTERS = ['all', 'public', 'internal', 'drafts'];

    public function index(Request $request): View
    {
        $filter = in_array($request->query('show'), self::FILTERS, true) ? $request->query('show') : 'all';
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->integer('category') ?: null;

        $articles = KbArticle::query()->with(['category', 'editor:id,name'])
            ->when($filter === 'public', fn (Builder $b) => $b->where('visibility', 'public'))
            ->when($filter === 'internal', fn (Builder $b) => $b->where('visibility', 'internal'))
            ->when($filter === 'drafts', fn (Builder $b) => $b->where('is_active', false))
            ->when($categoryId, fn (Builder $b) => $b->where('category_id', $categoryId))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('question', 'like', "%$q%")->orWhere('question_en', 'like', "%$q%")
                ->orWhere('answer', 'like', "%$q%")->orWhere('answer_en', 'like', "%$q%")->orWhere('tags', 'like', "%$q%")))
            ->get();
        $categories = KbCategory::query()->withCount('articles')->orderBy('sort_order')->orderBy('id')->get();
        $order = $categories->pluck('id')->flip();
        $grouped = $articles->sortBy(fn (KbArticle $a) => [$a->category_id === null ? PHP_INT_MAX : ($order[$a->category_id] ?? PHP_INT_MAX), $a->sort_order, $a->id])
            ->groupBy(fn (KbArticle $a) => (string) ($a->category_id ?? ''));

        return view('dashboard.kb.index', [
            'grouped' => $grouped,
            'categories' => $categories,
            'filter' => $filter,
            'q' => $q,
            'categoryId' => $categoryId,
            'counts' => [
                'all' => KbArticle::query()->count(),
                'public' => KbArticle::query()->where('visibility', 'public')->count(),
                'internal' => KbArticle::query()->where('visibility', 'internal')->count(),
                'drafts' => KbArticle::query()->where('is_active', false)->count(),
            ],
            'filtered' => $filter !== 'all' || $q !== '' || $categoryId !== null,
        ]);
    }

    public function create(Request $request): View
    {
        $article = new KbArticle(['visibility' => 'internal', 'is_active' => true, 'category_id' => $request->integer('category') ?: null]);

        return view('dashboard.kb.form', ['article' => $article, 'categories' => $this->categoryOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) KbArticle::query()->where('category_id', $data['category_id'])->max('sort_order') + 1;
        $article = KbArticle::query()->create($data + ['updated_by' => $request->user()->id]);
        Audit::record('kb.created', $article->question, ['id' => $article->id, 'visibility' => $article->visibility, 'active' => $article->is_active]);

        return redirect()->route('dash.kb')->with('ok', __('Answer saved.'));
    }

    public function edit(KbArticle $article): View
    {
        return view('dashboard.kb.form', ['article' => $article, 'categories' => $this->categoryOptions()]);
    }

    public function update(Request $request, KbArticle $article): RedirectResponse
    {
        $data = $this->validated($request);
        if ($data['category_id'] !== $article->category_id) {
            $data['sort_order'] = (int) KbArticle::query()->where('category_id', $data['category_id'])->max('sort_order') + 1;
        }
        $article->fill($data + ['updated_by' => $request->user()->id]);
        $changed = array_keys($article->getDirty());
        $article->save();
        Audit::record('kb.updated', $article->question, ['id' => $article->id, 'changed' => array_values(array_diff($changed, ['updated_by', 'sort_order']))]);

        return redirect()->route('dash.kb')->with('ok', __('Answer saved.'));
    }

    /** Publish a draft or take an answer off, from the list. */
    public function toggle(Request $request, KbArticle $article): RedirectResponse
    {
        $article->update(['is_active' => ! $article->is_active, 'updated_by' => $request->user()->id]);
        Audit::record('kb.updated', $article->question, ['id' => $article->id, 'changed' => ['is_active'], 'active' => $article->is_active]);

        return back()->with('ok', $article->is_active ? __('The answer is on.') : __('The answer is off (draft).'));
    }

    public function destroy(KbArticle $article): RedirectResponse
    {
        $article->delete();
        Audit::record('kb.deleted', $article->question, ['id' => $article->id, 'visibility' => $article->visibility]);

        return redirect()->route('dash.kb')->with('ok', __('Answer deleted.'));
    }

    /** Up or down among the answers of the same category. */
    public function move(Request $request, KbArticle $article): RedirectResponse
    {
        $siblings = KbArticle::query()->where('category_id', $article->category_id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $this->reorder($siblings, $article->id, $request->input('direction') === 'up', fn (int $id, int $sort) => KbArticle::query()->whereKey($id)->update(['sort_order' => $sort]));

        return back();
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80']);
        $category = KbCategory::query()->create($data + ['sort_order' => (int) KbCategory::query()->max('sort_order') + 1]);
        Audit::record('kb.category_created', $category->name, ['id' => $category->id]);

        return back()->with('ok', __('Category added.'));
    }

    public function updateCategory(Request $request, KbCategory $category): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80', 'is_active' => 'nullable|boolean']);
        $category->update(['name' => $data['name'], 'name_en' => $data['name_en'] ?? null, 'is_active' => $request->boolean('is_active')]);
        Audit::record('kb.category_updated', $category->name, ['id' => $category->id, 'active' => $category->is_active]);

        return back()->with('ok', __('Saved.'));
    }

    /** Its answers stay, without a category. */
    public function destroyCategory(KbCategory $category): RedirectResponse
    {
        $category->delete();
        Audit::record('kb.category_deleted', $category->name, ['id' => $category->id]);

        return redirect()->route('dash.kb')->with('ok', __('Category deleted. Its answers stay, without a category.'));
    }

    public function moveCategory(Request $request, KbCategory $category): RedirectResponse
    {
        $ids = KbCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $this->reorder($ids, $category->id, $request->input('direction') === 'up', fn (int $id, int $sort) => KbCategory::query()->whereKey($id)->update(['sort_order' => $sort]));

        return back();
    }

    /**
     * Adds the suggested questions of the business type as drafts (inactive), each in its suggested category; a
     * question that is already there is not added twice. The answers are templates with [brackets] to fill in.
     */
    public function suggested(Request $request): RedirectResponse
    {
        $added = 0;
        $categories = [];
        foreach (KnowledgeBaseTemplates::for(BusinessType::current()) as $template) {
            if (KbArticle::query()->where('question', $template['question'])->exists()) {
                continue;
            }
            [$name, $nameEn] = KnowledgeBaseTemplates::CATEGORIES[$template['category']];
            $categories[$name] ??= KbCategory::query()->firstOrCreate(['name' => $name], [
                'name_en' => $nameEn, 'sort_order' => (int) KbCategory::query()->max('sort_order') + 1,
            ]);
            $category = $categories[$name];
            KbArticle::query()->create([
                'category_id' => $category->id, 'question' => $template['question'], 'question_en' => $template['question_en'],
                'answer' => $template['answer'], 'answer_en' => $template['answer_en'], 'visibility' => $template['visibility'],
                'tags' => $template['tags'], 'is_active' => false, 'updated_by' => $request->user()->id,
                'sort_order' => (int) KbArticle::query()->where('category_id', $category->id)->max('sort_order') + 1,
            ]);
            $added++;
        }
        if ($added > 0) {
            Audit::record('kb.suggested', BusinessType::label(), ['added' => $added]);
        }

        return redirect()->route('dash.kb', ['show' => 'drafts'])->with('ok', $added > 0
            ? __(':n suggested questions added as drafts: fill in the [brackets], then turn each one on.', ['n' => $added])
            : __('The suggested questions are already in your list.'));
    }

    /** The staff drawer's data: every shown answer, internal and public (cached per restaurant). */
    public function feed(): JsonResponse
    {
        return response()->json(KnowledgeBase::staffFeed());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'question' => 'required|string|max:255',
            'question_en' => 'nullable|string|max:255',
            'answer' => 'required|string|max:5000',
            'answer_en' => 'nullable|string|max:5000',
            'visibility' => ['required', Rule::in(array_keys(KbArticle::VISIBILITIES))],
            'category_id' => ['nullable', 'integer', Rule::exists('kb_categories', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'tags' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        return [
            'question' => trim($data['question']), 'question_en' => trim((string) ($data['question_en'] ?? '')) ?: null,
            'answer' => trim($data['answer']), 'answer_en' => trim((string) ($data['answer_en'] ?? '')) ?: null,
            'visibility' => $data['visibility'], 'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'tags' => trim((string) ($data['tags'] ?? '')) ?: null, 'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return Collection<int, KbCategory> */
    private function categoryOptions()
    {
        return KbCategory::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Moves $id one place up or down in $ids and writes the new positions 1..n (KnowledgeBase cache dropped once).
     *
     * @param  list<int>  $ids
     * @param  \Closure(int, int): mixed  $write
     */
    private function reorder(array $ids, int $id, bool $up, \Closure $write): void
    {
        $i = array_search($id, $ids, true);
        $j = $up ? $i - 1 : $i + 1;
        if ($i === false || ! isset($ids[$j])) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $position => $one) {
            $write($one, $position + 1);
        }
        KnowledgeBase::forget((string) tenant()->getTenantKey());
    }
}
