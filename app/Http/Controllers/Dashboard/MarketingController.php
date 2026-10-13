<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Popup;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Timed offers ("happy hour") and popups shown on the public menu. */
class MarketingController extends Controller
{
    public function index(): View
    {
        return view('dashboard.marketing', [
            'offers' => Offer::query()->with(['category', 'branch'])->latest('id')->get(),
            'popups' => Popup::query()->with('item')->latest('id')->get(),
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'branches' => Branch::query()->orderBy('sort_order')->get(),
            'items' => MenuItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveOffer(Request $request, ?Offer $offer = null): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'title' => 'required|string|max:80', 'title_en' => 'nullable|string|max:80',
            'discount_percent' => 'required|integer|min:1|max:90',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'days' => 'array', 'days.*' => 'integer|between:0,6',
            'starts_at' => 'nullable|date_format:H:i', 'ends_at' => 'nullable|date_format:H:i|required_with:starts_at',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);
        $values = $data + ['title_en' => '', 'days' => null, 'is_active' => $request->boolean('is_active', true)];
        $values['days'] = isset($data['days']) && count($data['days']) < 7 ? array_values(array_map('intval', $data['days'])) : null;
        ($offer?->exists ? $offer : new Offer())->fill($values)->save();
        return back()->with('ok', __('Offer saved.'));
    }

    public function toggleOffer(Offer $offer): RedirectResponse
    {
        $offer->update(['is_active' => !$offer->is_active]);
        return back();
    }

    public function deleteOffer(Offer $offer): RedirectResponse
    {
        $offer->delete();
        return back()->with('ok', __('Offer deleted.'));
    }

    public function savePopup(Request $request, ImageService $images): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'type' => ['required', Rule::in(['image', 'item'])],
            'title' => 'nullable|string|max:80', 'title_en' => 'nullable|string|max:80',
            'body' => 'nullable|string|max:300', 'body_en' => 'nullable|string|max:300',
            'image' => 'nullable|file|max:5120',
            'menu_item_id' => ['nullable', 'required_if:type,item', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'button_text' => 'nullable|string|max:40', 'link_url' => 'nullable|url|max:300',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'frequency' => ['required', Rule::in(Popup::FREQUENCIES)], 'delay_seconds' => 'nullable|integer|min:0|max:30',
        ]);
        if ($data['type'] === 'image' && !$request->hasFile('image')) {
            return back()->withErrors(['image' => __('Add a picture for the popup.')]);
        }
        $values = collect($data)->except('image')->map(fn ($v) => $v ?? null)->all();
        $values['title'] ??= '';
        $values['title_en'] ??= '';
        $values['button_text'] ??= '';
        $values['delay_seconds'] ??= 2;
        if ($request->hasFile('image')) {
            $values['image'] = $images->store($request->file('image'), 'cover');
        }
        Popup::query()->create($values);
        return back()->with('ok', __('Popup saved.'));
    }

    public function togglePopup(Popup $popup): RedirectResponse
    {
        $popup->update(['is_active' => !$popup->is_active]);
        return back();
    }

    public function deletePopup(Popup $popup, ImageService $images): RedirectResponse
    {
        $images->delete($popup->image);
        $popup->delete();
        return back()->with('ok', __('Popup deleted.'));
    }
}
