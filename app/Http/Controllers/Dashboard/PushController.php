<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Push\FcmClient;
use App\Services\Push\StaffPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Stancl\Tenancy\Database\TenantScope;

/**
 * Push notifications on the staff's phones (EgHub restaurant and waiter apps). The app's page calls register() with
 * its FCM token on every load (window.EgHubNative.getPushToken(), resources/js/native-push.js) and unregister() on
 * sign-out; the staff member picks which notifications they want on /notifications/push.
 */
class PushController extends Controller
{
    /** The apps of the EgHub Android family (window.EgHubNative.getInfo().app). */
    public const APPS = ['pos', 'restaurant', 'driver', 'kiosk', 'waiter'];

    /** Phones kept per staff member: a new one beyond this replaces the one not seen the longest. */
    public const MAX_DEVICES = 5;

    /**
     * Saves (or refreshes) this phone's token for the signed-in user. The token is unique everywhere: a phone that
     * was signed in with another account, even of another restaurant, moves to this one.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|min:20|max:4096',
            'app' => ['required', Rule::in(self::APPS)],
            'platform' => ['nullable', Rule::in(['android'])],
            'version' => 'nullable|string|max:30',
            'device' => 'nullable|string|max:120',
        ]);
        $user = $request->user();
        $hash = DeviceToken::hashOf($data['token']);
        $token = DeviceToken::query()->withoutGlobalScope(TenantScope::class)->where('token_hash', $hash)->first() ?? new DeviceToken;
        $token->forceFill([
            'tenant_id' => tenant()->getTenantKey(), 'user_id' => $user->id, 'token' => $data['token'], 'token_hash' => $hash,
            'app' => $data['app'], 'platform' => $data['platform'] ?? 'android',
            'app_version' => $data['version'] ?? null, 'device' => isset($data['device']) ? mb_substr(trim($data['device']), 0, 120) : null,
            'last_seen_at' => now(),
        ])->save();
        DeviceToken::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->orderByDesc('id')
            ->skip(self::MAX_DEVICES)->take(100)->get()->each->delete();

        return response()->json(['ok' => true, 'configured' => app(FcmClient::class)->configured()]);
    }

    /** This phone stops getting notifications for the signed-in user (signing out of the app). */
    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:4096']);
        DeviceToken::query()->where('user_id', $request->user()->id)->where('token_hash', DeviceToken::hashOf($data['token']))->delete();

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): View
    {
        $user = $request->user();

        return view('dashboard.push', [
            'preferences' => StaffPush::preferences($user),
            'devices' => DeviceToken::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->get(),
            'configured' => app(FcmClient::class)->configured(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $events = array_keys(StaffPush::preferences($user)); // only what this user may receive
        $request->validate(['events' => 'nullable|array', 'events.*' => ['string', Rule::in($events)]]);
        $on = (array) $request->input('events', []);
        $user->forceFill(['push_preferences' => collect($events)->mapWithKeys(fn (string $e) => [$e => in_array($e, $on, true)])->all()])->save();

        return back()->with('ok', __('Saved.'));
    }

    public function removeDevice(Request $request, int $device): RedirectResponse
    {
        DeviceToken::query()->where('user_id', $request->user()->id)->whereKey($device)->delete();

        return back()->with('ok', __('The phone was removed.'));
    }
}
