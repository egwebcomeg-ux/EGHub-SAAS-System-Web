<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\MenuFunnelCount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Beacon sent by the public menu: a step this visit reached (once per visit) or where the cart's own checks stopped
 * the guest. Only known steps and stops are counted; the placed order and its refusals are counted by OrderController.
 */
class FunnelController extends Controller
{
    public function store(Request $request, string $event): Response
    {
        if (in_array($event, MenuFunnelCount::STEPS, true) && $event !== 'placed') {
            MenuFunnelCount::bump($event);
        } elseif ($event === MenuFunnelCount::STOP && in_array($stop = (string) $request->query('why'), MenuFunnelCount::STOPS, true)) {
            MenuFunnelCount::bump(MenuFunnelCount::STOP, $stop);
        }

        return response()->noContent();
    }
}
