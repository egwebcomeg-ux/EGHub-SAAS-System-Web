<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BranchesController extends Controller
{
    public function index(): View
    {
        return view('dashboard.branches', [
            'branches' => Branch::query()->withCount('tables')->orderBy('sort_order')->orderBy('id')->get(),
            'canAdd' => Entitlements::current()->canAdd('branches'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rights = Entitlements::current();
        if (! $rights->canAdd('branches')) {
            return back()->withErrors(['limit' => $rights->upgradeMessage('branches')]);
        }
        $data = $this->validated($request);
        $base = Str::slug($data['name_en'] ?: '') ?: 'branch';
        $slug = $base;
        for ($i = 2; Branch::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }
        Branch::query()->create($data + ['slug' => $slug, 'sort_order' => (int) Branch::query()->max('sort_order') + 1]);

        return back()->with('ok', __('Branch added.'));
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');
        if (! $data['is_active'] && Branch::query()->where('is_active', true)->whereKeyNot($branch->id)->doesntExist()) {
            return back()->withErrors(['branch' => __('Keep at least one active branch.')]);
        }
        $branch->update($data);

        return back()->with('ok', __('Branch saved.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'name_en' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:200',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
            'working_hours' => 'nullable|string|max:100',
            'maps_url' => 'nullable|url|max:300',
        ]);

        return array_map(fn ($v) => $v ?? '', $data) + ['whatsapp' => null, 'maps_url' => null];
    }
}
