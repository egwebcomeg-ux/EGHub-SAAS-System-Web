<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvScreen;
use App\Support\ScreenDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «إزاي أظبط الشاشة دي تفضل شغالة»: one screen's kind of TV (ScreenDevices) with its setup, keep-awake and power-cut
 * help, and «اتغيّر نوع الشاشة؟» to pick another kind. Changing the kind does not reload the screen.
 */
class TvScreenDeviceController extends Controller
{
    public function show(Request $request, TvScreen $screen): View
    {
        $type = ScreenDevices::find((string) $request->query('type')) ? (string) $request->query('type') : $screen->device_type;

        return view('dashboard.tv-device', [
            'screen' => $screen,
            'type' => ScreenDevices::find($type) ? $type : null,
            'changing' => $request->boolean('change') || ! ScreenDevices::find($screen->device_type),
        ]);
    }

    public function update(Request $request, TvScreen $screen): RedirectResponse
    {
        $data = $request->validate(['device_type' => ['required', 'string', Rule::in(ScreenDevices::keys())]]);
        // the device type is for the owner only: the playlist fingerprint (updated_at) stays as it is
        $screen->writeQuietly(['device_type' => $data['device_type']]);

        return redirect()->route('dash.tv.device', $screen)->with('ok', __('Screen type saved.'));
    }
}
