<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\TranslateMenu;
use App\Services\Import\Ai\AiProviders;
use App\Support\MenuLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** "Translate with AI": fills the empty extra-language texts of the whole menu in the background (TranslateMenu). */
class MenuTranslationController extends Controller
{
    public function start(): RedirectResponse
    {
        if (MenuLocales::enabled() === []) {
            return back()->withErrors(['translate' => __('Choose the extra languages in Settings first.')]);
        }
        if (! AiProviders::configured()) {
            return back()->withErrors(['translate' => __('Add your AI API key in Settings first.')]);
        }
        if (! TranslateMenu::start()) {
            return back()->with('ok', __('The translation is already running.'));
        }

        return back()->with('ok', __('Translating the menu in the background. You can keep working.'));
    }

    public function status(): JsonResponse
    {
        return response()->json(TranslateMenu::status() ?? ['state' => 'none']);
    }
}
