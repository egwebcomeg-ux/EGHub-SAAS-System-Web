<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\ScreenDevices;
use Illuminate\View\View;

/** Marketing site: «الشاشات اللي بتشتغل مع EgHub» (/supported-screens), every TV and device with how to set it up. */
class SupportedScreensController extends Controller
{
    public function __invoke(): View
    {
        return view('landing.supported-screens', ['devices' => ScreenDevices::all(), 'groups' => ScreenDevices::groups()]);
    }
}
