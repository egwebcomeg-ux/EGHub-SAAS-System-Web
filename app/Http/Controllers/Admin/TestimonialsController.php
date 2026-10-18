<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Testimonial;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Real owner quotes on egmenu.app: written by the platform team, published only with the owner's consent. */
class TestimonialsController extends Controller
{
    public function index(): View
    {
        return view('admin.testimonials.index', [
            'testimonials' => Testimonial::query()->with('tenant')->orderBy('sort')->orderByDesc('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.testimonials.form', ['testimonial' => new Testimonial(['is_published' => false, 'sort' => 0]), 'restaurants' => $this->restaurants()]);
    }

    public function store(Request $request, ImageService $images): RedirectResponse
    {
        $testimonial = new Testimonial;
        $this->fill($testimonial, $this->validated($request), $request, $images);
        $testimonial->save();

        return redirect()->route('admin.testimonials')->with('ok', __('Testimonial saved.'));
    }

    public function edit(Testimonial $testimonial): View
    {
        return view('admin.testimonials.form', ['testimonial' => $testimonial, 'restaurants' => $this->restaurants()]);
    }

    public function update(Request $request, Testimonial $testimonial, ImageService $images): RedirectResponse
    {
        $this->fill($testimonial, $this->validated($request), $request, $images);
        $testimonial->save();

        return redirect()->route('admin.testimonials')->with('ok', __('Testimonial saved.'));
    }

    /** Show or hide on the home page; showing needs the owner's recorded consent. */
    public function publish(Testimonial $testimonial): RedirectResponse
    {
        if (! $testimonial->is_published && ! $testimonial->consent_at) {
            return back()->withErrors(['consent' => __('Record the owner’s consent before publishing.')]);
        }
        $testimonial->update(['is_published' => ! $testimonial->is_published]);

        return back()->with('ok', $testimonial->is_published ? __('Testimonial published.') : __('Testimonial hidden.'));
    }

    public function destroy(Testimonial $testimonial, ImageService $images): RedirectResponse
    {
        $images->delete($testimonial->photo);
        $testimonial->delete();

        return redirect()->route('admin.testimonials')->with('ok', __('Testimonial deleted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'role' => 'nullable|string|max:120',
            'role_en' => 'nullable|string|max:120',
            'quote' => 'required|string|max:600',
            'quote_en' => 'nullable|string|max:600',
            'rating' => 'nullable|integer|between:1,5',
            'tenant_id' => 'nullable|string|exists:tenants,id',
            'sort' => 'nullable|integer|between:-999,999',
            'consent' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'photo' => 'nullable|image|max:5120',
            'remove_photo' => 'nullable|boolean',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fill(Testimonial $testimonial, array $data, Request $request, ImageService $images): void
    {
        $testimonial->fill([
            'name' => $data['name'], 'role' => $data['role'] ?? '', 'role_en' => $data['role_en'] ?? '',
            'quote' => $data['quote'], 'quote_en' => $data['quote_en'] ?? null,
            'rating' => $data['rating'] ?? null, 'tenant_id' => $data['tenant_id'] ?? null, 'sort' => (int) ($data['sort'] ?? 0),
        ]);
        if ($request->boolean('consent')) {
            $testimonial->consent_at ??= now();
        } else {
            $testimonial->consent_at = null;
        }
        // never live without consent, whatever the checkbox said
        $testimonial->is_published = $request->boolean('is_published') && $testimonial->consent_at !== null;

        if ($request->boolean('remove_photo') && $testimonial->photo) {
            $images->delete($testimonial->photo);
            $testimonial->photo = null;
        }
        if ($request->hasFile('photo')) {
            $images->delete($testimonial->photo);
            $testimonial->photo = $images->store($request->file('photo'), 'testimonial');
        }
    }

    /** Restaurant slugs for the optional link (suggestions only; the slug is validated on save). @return list<string> */
    private function restaurants(): array
    {
        return Tenant::query()->where('status', '!=', 'suspended')->orderBy('id')->pluck('id')->all();
    }
}
