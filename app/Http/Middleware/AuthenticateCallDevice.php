<?php

namespace App\Http\Middleware;

use App\Models\CallDevice;
use App\Models\PlatformAdmin;
use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A linked sales phone: its token (Authorization: Bearer) stands for its admin, while they may still handle sales. */
class AuthenticateCallDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $device = $token !== '' ? CallDevice::query()->with('admin')->where('token_hash', hash('sha256', $token))->first() : null;
        $admin = $device?->admin;
        if (! $admin instanceof PlatformAdmin || ! $admin->is_active || ! $admin->allows('crm.manage') || TwoFactor::adminSetupRequired($admin)) {
            return response()->json(['ok' => false, 'error' => 'not_linked'], 401);
        }
        // a phone silent for months (lost, replaced) is linked again by hand before its token works
        if ($device->last_seen_at !== null && $device->last_seen_at->lt(now()->subDays(CallDevice::IDLE_DAYS))) {
            return response()->json(['ok' => false, 'error' => 'not_linked'], 401);
        }
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }
        $request->attributes->set('callDevice', $device);

        return $next($request);
    }
}
