<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\SignIn\GuestIdentity;
use App\Services\Customers\SignIn\PhoneCodeSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «ادخل برقم موبايلك» on the restaurant's host (App\Services\Customers\SignIn\PhoneCodeSignIn): a code to the mobile
 * by the restaurant's own WhatsApp number or SMS, «ابعته بطريقة تانية» (the other ways), and the code typed back.
 * JSON only, private (AccountController::HEADERS), never cached.
 */
class PhoneCodeController extends Controller
{
    public function start(Request $request, PhoneCodeSignIn $codes): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20', 'channel' => 'nullable|string|max:12']);
        $phone = normalize_phone($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => __('Enter an Egyptian mobile number, e.g. 01012345678.')], 422, AccountController::HEADERS);
        }
        $channel = $data['channel'] ?? null;
        $masked = GuestAccount::maskedPhone($phone);

        $result = $codes->start($phone, $channel, (string) $request->ip());
        $alternatives = PhoneCodeSignIn::alternatives($phone, $result['channel']);

        return match ($result['status']) {
            'sent' => response()->json(['status' => 'sent', 'channel' => $result['channel'],
                'message' => match ($result['channel']) {
                    'sms' => __('We sent a code by SMS to :phone.', ['phone' => $masked]),
                    'email' => __('We sent a code to your email :email (the one we have for this mobile). Check the spam folder too.',
                        ['email' => PhoneCodeSignIn::maskEmail((string) PhoneCodeSignIn::emailOf($phone))]),
                    default => __('We sent a code on WhatsApp to :phone.', ['phone' => $masked]),
                },
                'resend_in' => PhoneCodeSignIn::RESEND_SECONDS, 'alternatives' => $alternatives], 200, AccountController::HEADERS),
            'wait' => response()->json(['status' => 'sent', 'channel' => $result['channel'],
                'message' => __('A code is already on its way to :phone. You can ask for a new one in :n seconds.', ['phone' => $masked, 'n' => $result['wait']]),
                'resend_in' => $result['wait'], 'alternatives' => $alternatives], 200, AccountController::HEADERS),
            'blocked' => response()->json(['message' => __('Too many codes for this mobile. Please try again in an hour, or choose another way.'),
                'alternatives' => $alternatives], 429, AccountController::HEADERS),
            default => response()->json(['status' => 'choose',
                'message' => $result['status'] === 'failed' ? __('We could not send the code right now. Choose another way.')
                    : __('We cannot send a code to this mobile here yet. Sign in with your email, or with your mobile + a previous order number.'),
                'alternatives' => $alternatives], 200, AccountController::HEADERS),
        };
    }

    public function verify(Request $request, PhoneCodeSignIn $codes): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20', 'code' => 'required|string|max:12']);
        $phone = normalize_phone($data['phone']);
        $customer = $phone ? $codes->verify($phone, $data['code']) : null;
        if (! $customer) {
            return response()->json(['message' => __('This code is wrong or expired. Check the latest message, or ask for a new code.')], 422, AccountController::HEADERS);
        }
        // a Google or email account this browser had joins the guest of this mobile
        GuestIdentity::adopt(GuestAccount::fromRequest($request), $customer);
        $customer->refresh();

        return response()->json(['ok' => true, 'redirect' => route('menu.show'), 'account' => GuestAccount::url($customer)], 200, AccountController::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)))
            ->withCookie(GuestAccount::phoneProofCookie($customer));
    }
}
