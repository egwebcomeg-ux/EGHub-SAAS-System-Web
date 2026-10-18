<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\ModuleChoice;
use App\Services\WhatsApp\PhoneVerification;
use App\Support\BusinessType;
use App\Support\SiteModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "What will you use?": the module picker shown right after signup (?welcome=1, skippable) and from Settings.
 * Saving only changes what the dashboard puts first; plans, add-ons and access stay exactly as they are.
 * The routes sit behind permission:settings.manage, so staff without it never reach the step.
 */
class ModulesController extends Controller
{
    public function edit(Request $request): View
    {
        $selected = ModuleChoice::preselected();
        $pricing = SiteModules::pricing();

        return view('dashboard.modules', [
            'groups' => SiteModules::groups(),
            'grouped' => SiteModules::grouped(),
            'selected' => $selected,
            'hasChoice' => ModuleChoice::hasChoice(),
            'welcome' => $request->boolean('welcome'),
            'businessType' => BusinessType::current(),
            'coverage' => ModuleChoice::coverage($selected),
            'where' => fn (array $module) => SiteModules::where($module, $pricing),
        ]);
    }

    /** The plan summary for a selection still being ticked (the page refreshes it without saving). */
    public function summary(Request $request): View
    {
        return view('dashboard.partials.modules-summary', ['coverage' => ModuleChoice::coverage($this->slugs($request))]);
    }

    public function update(Request $request): RedirectResponse
    {
        $saved = ModuleChoice::save($this->slugs($request));
        Audit::record('modules.updated', implode(', ', $saved));

        if ($request->boolean('welcome')) {
            return $this->next($request)->with('ok', __('Done! Your dashboard now puts what you use first.'));
        }

        return redirect()->route('dash.modules')->with('ok', __('Saved. Your dashboard now puts what you use first.'));
    }

    /** "Later": nothing is saved, the dashboard stays complete; the step stays in Settings. */
    public function skip(Request $request): RedirectResponse
    {
        return $this->next($request)->with('ok', __('No problem. You can pick your modules any time from Settings.'));
    }

    /** @return list<string> */
    private function slugs(Request $request): array
    {
        $data = $request->validate([
            'modules' => ['array', 'max:'.count(SiteModules::all())],
            'modules.*' => ['string', Rule::in(array_keys(SiteModules::all()))],
        ]);

        return array_values($data['modules'] ?? []);
    }

    /** After the welcome step: confirming the mobile when the platform can, else the home page. */
    private function next(Request $request): RedirectResponse
    {
        return redirect()->route(PhoneVerification::pendingFor($request->user()) ? 'dash.phone' : 'dash.home');
    }
}
