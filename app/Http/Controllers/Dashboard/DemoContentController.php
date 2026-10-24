<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\DemoContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Clear the sample data": the owner only, confirmed, written to the audit trail. Removes the listed rows only. */
class DemoContentController extends Controller
{
    public function destroy(Request $request, DemoContent $demo): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $request->validate(['confirm' => 'accepted']);
        if (! DemoContent::present()) {
            return back()->with('ok', __('There is no sample data left.'));
        }
        $removed = $demo->clear();
        Audit::record('demo.cleared', '', ['rows' => $removed]);

        return redirect()->route('dash.home')->with('ok', __('The sample data was cleared. Everything you see now is yours.'));
    }
}
