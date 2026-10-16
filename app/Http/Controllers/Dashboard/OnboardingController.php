<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Onboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The onboarding checklist's actions (skip a step, hide the card) and the short illustrated tutorial. */
class OnboardingController extends Controller
{
    public function skip(Request $request): RedirectResponse
    {
        abort_unless(Onboarding::visibleTo($request->user()), 403);
        $data = $request->validate(['step' => ['required', Rule::in(Onboarding::STEPS)]]);
        Onboarding::skip($data['step']);

        return redirect()->route('dash.home');
    }

    public function dismiss(Request $request): RedirectResponse
    {
        abort_unless(Onboarding::visibleTo($request->user()), 403);
        Onboarding::dismiss();

        return redirect()->route('dash.home')->with('ok', __('Checklist hidden. The tutorial stays available from the home page.'));
    }

    public function help(): View
    {
        return view('dashboard.help');
    }
}
