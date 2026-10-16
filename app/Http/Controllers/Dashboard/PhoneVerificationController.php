<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\WhatsApp\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Confirm your number on WhatsApp": one page with the wa.me button, a small form to change the number (new code),
 * and a JSON status the page polls so it can show success the moment the webhook confirms. Never a gate: the owner
 * can leave the page and use the whole dashboard unconfirmed.
 */
class PhoneVerificationController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if ($user->phone && ! $user->hasVerifiedPhone() && ! $user->phone_verification_code) {
            PhoneVerification::issue($user);
        }

        return view('dashboard.phone', [
            'user' => $user,
            'link' => PhoneVerification::link($user),
            'message' => $user->phone_verification_code ? PhoneVerification::message($user->phone_verification_code) : null,
            'number' => PhoneVerification::number(),
        ]);
    }

    /** "I changed my number": a different (unused) Egyptian mobile gets a fresh code; the old confirmation is dropped. */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['phone' => normalize_phone((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'phone' => ['required', 'regex:/^01[0125]\d{8}$/', Rule::unique(User::class, 'phone')->ignore($user->id)],
        ], ['phone.regex' => __('Enter a valid Egyptian mobile number.')]);

        $user->forceFill(['phone' => $data['phone']])->save();
        PhoneVerification::issue($user);
        Audit::record('phone.changed', $data['phone']);

        return redirect()->route('dash.phone')->with('ok', __('Number updated. Send the new message to confirm it.'));
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();

        return response()->json(['verified' => $user->hasVerifiedPhone(), 'phone' => $user->phone]);
    }
}
