<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Kiosk;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Self-order kiosks (kiosk pack): one secret link per tablet, tied to a branch, with its own settings. */
class KiosksController extends Controller
{
    /** The kiosks live on the "Screens & devices" page now (DevicesController); the old address opens its kiosk tab. */
    public function index(): RedirectResponse
    {
        return redirect()->route('dash.devices', ['tab' => 'kiosk']);
    }

    public function store(Request $request): RedirectResponse
    {
        Kiosk::query()->create($this->validated($request) + ['token' => random_token(10)]);

        return back()->with('ok', __('Kiosk saved.'));
    }

    public function update(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $kiosk->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('Kiosk saved.'));
    }

    /** New link: the old one stops working (e.g. the tablet was lost or the link was shared). */
    public function regenerate(Kiosk $kiosk): RedirectResponse
    {
        $kiosk->update(['token' => random_token(10)]);

        return back()->with('ok', __('New link created. Open it on the tablet again.'));
    }

    public function destroy(Kiosk $kiosk): RedirectResponse
    {
        $kiosk->delete();

        return back()->with('ok', __('Kiosk deleted.'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'idle_seconds' => 'required|integer|min:15|max:600',
            'order_types' => 'required|array|min:1',
            'order_types.*' => Rule::in(Kiosk::ORDER_TYPES),
        ]);

        return [
            'name' => $data['name'], 'branch_id' => (int) $data['branch_id'],
            'settings' => [
                'idle_seconds' => (int) $data['idle_seconds'],
                'order_types' => array_values(array_intersect(Kiosk::ORDER_TYPES, $data['order_types'])),
                'require_phone' => $request->boolean('require_phone'),
                'print_number' => $request->boolean('print_number'),
            ],
        ];
    }
}
