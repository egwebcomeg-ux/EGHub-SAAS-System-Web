<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TV menu boards (Business): each screen picks its categories and layout and gets its own link. */
class TvScreensController extends Controller
{
    /** The screens live on the "Screens & devices" page now (DevicesController); the old address opens its TV tab. */
    public function index(): RedirectResponse
    {
        return redirect()->route('dash.devices', ['tab' => 'tv']);
    }

    public function store(Request $request): RedirectResponse
    {
        TvScreen::query()->create($this->validated($request) + ['token' => random_token(10)]);

        return back()->with('ok', __('Screen saved.'));
    }

    public function update(Request $request, TvScreen $screen): RedirectResponse
    {
        $screen->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);
        $screen->touch(); // the TV reloads on its next check even when only the layout changed

        return back()->with('ok', __('Screen saved.'));
    }

    /** New link: the old one stops working (e.g. it was shared by mistake). */
    public function regenerate(TvScreen $screen): RedirectResponse
    {
        $screen->update(['token' => random_token(10)]);

        return back()->with('ok', __('New link created. Open it on the TV again.'));
    }

    public function destroy(TvScreen $screen): RedirectResponse
    {
        $screen->delete();

        return back()->with('ok', __('Screen deleted.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'category_ids' => 'nullable|array|max:100',
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'layout' => ['required', Rule::in(TvScreen::LAYOUTS)],
            'columns' => 'required|integer|min:1|max:4',
            'rotate_seconds' => 'required|integer|min:5|max:120',
            'theme' => ['required', Rule::in(TvScreen::THEMES)],
        ]);
        $data['category_ids'] = array_values(array_map('intval', $data['category_ids'] ?? [])) ?: null;
        $data['show_images'] = $request->boolean('show_images');

        return $data;
    }
}
