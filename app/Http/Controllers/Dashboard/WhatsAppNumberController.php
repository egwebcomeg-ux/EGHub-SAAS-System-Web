<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConnection;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\SupportAccess;
use App\Services\WhatsApp\OwnNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «اربط رقم واتساب مطعمك»: the owner (or a manager: settings.manage) pastes the WhatsApp Business account id, the
 * phone number id, a permanent system-user token and the app secret from Meta; we test them, subscribe the app to
 * the account's webhooks and create our message templates there (App\Services\WhatsApp\OwnNumber).
 *
 * The token and the secret are never shown back (masked) and never audited. Saving, re-creating templates and
 * disconnecting are blocked for EgWeb support signed in from the admin (support.blocked); the page hides the form.
 */
class WhatsAppNumberController extends Controller
{
    public function show(Request $request): View
    {
        $connection = OwnNumber::current();

        return view('dashboard.whatsapp-number', [
            'connection' => $connection,
            'allowed' => Entitlements::current()->allows('whatsapp_notifications'),
            'support' => SupportAccess::active($request),
            'callbackUrl' => $connection ? route('webhooks.whatsapp.own', $connection->webhook_key) : null,
            'templates' => collect(OwnNumber::definitions())->groupBy('name')->map(fn ($rows) => [
                'key' => $rows->first()['key'],
                'languages' => $rows->pluck('language')->all(),
            ]),
        ]);
    }

    /** «احفظ واربط»: saves the credentials, tests them and, once connected, subscribes and creates the templates. */
    public function save(Request $request, OwnNumber $own): RedirectResponse
    {
        $existing = OwnNumber::current();
        $data = $request->validate([
            'waba_id' => 'required|string|regex:/^\d{5,30}$/',
            'phone_number_id' => 'required|string|regex:/^\d{5,30}$/',
            'access_token' => [$existing ? 'nullable' : 'required', 'string', 'min:20', 'max:1000', 'regex:/^\S+$/'],
            'app_secret' => [$existing?->app_secret ? 'nullable' : 'required', 'string', 'regex:/^[A-Fa-f0-9]{32}$/'],
        ], [], [
            'waba_id' => __('WhatsApp Business account ID'), 'phone_number_id' => __('Phone number ID'),
            'access_token' => __('Access token'), 'app_secret' => __('App secret'),
        ]);
        $phoneId = $data['phone_number_id'];
        $taken = $phoneId === (string) config('whatsapp.meta.phone_id') || WhatsAppConnection::query()->withoutGlobalScopes()
            ->where('phone_number_id', $phoneId)->where('tenant_id', '!=', tenant()->getTenantKey())->exists();
        if ($taken) {
            throw ValidationException::withMessages(['phone_number_id' => __('This WhatsApp number is already connected to another account.')]);
        }

        $connection = $existing ?? new WhatsAppConnection(['webhook_key' => random_token(16), 'verify_token' => random_token(20)]);
        $changed = ! $existing || $existing->waba_id !== $data['waba_id'] || $existing->phone_number_id !== $phoneId;
        $connection->fill(['waba_id' => $data['waba_id'], 'phone_number_id' => $phoneId, 'status' => 'draft', 'last_error' => null]);
        if (! empty($data['access_token'])) {
            $connection->access_token = $data['access_token'];
        }
        if (! empty($data['app_secret'])) {
            $connection->app_secret = strtolower($data['app_secret']);
        }
        if ($changed) {
            $connection->fill(['templates' => null, 'templates_checked_at' => null, 'subscribed_at' => null, 'display_phone' => null, 'verified_name' => null]);
        }
        $connection->save();

        $ok = $own->check($connection);
        if ($ok) {
            $own->subscribe($connection);
            $own->syncTemplates($connection);
        }
        Audit::record('whatsapp.own_number.saved', (string) ($connection->display_phone ?? $phoneId), [
            'waba_id' => $connection->waba_id, 'phone_number_id' => $connection->phone_number_id, 'status' => $connection->status,
        ]);

        return $ok
            ? back()->with('ok', __('Your WhatsApp number is connected. Our message templates were sent to Meta for approval.'))
            : back()->withErrors(['whatsapp_number' => $connection->last_error ?: __('The connection failed.')]);
    }

    /** «اختبر الربط»: reads the number and the account again (and subscribes the app if that had not worked). */
    public function test(OwnNumber $own): RedirectResponse
    {
        $connection = OwnNumber::current() ?? abort(404);
        if (! $own->check($connection)) {
            return back()->withErrors(['whatsapp_number' => $connection->last_error ?: __('The connection failed.')]);
        }
        if (! $connection->subscribed_at) {
            $own->subscribe($connection);
        }

        return back()->with('ok', __('Connection works: :phone (:name).', [
            'phone' => $connection->display_phone ?: $connection->phone_number_id, 'name' => $connection->verified_name ?: '—',
        ]));
    }

    /** «حدّث الحالة»: reads the templates' approval status and submits any of ours still missing. */
    public function templates(OwnNumber $own): RedirectResponse
    {
        $connection = OwnNumber::current() ?? abort(404);
        abort_unless($connection->isConnected(), 422, __('Test the connection first.'));
        $own->syncTemplates($connection);

        return back()->with('ok', __('Template status updated.'));
    }

    /** «افصل الرقم»: messages go out from the EgHub number again; the stored token and secret are deleted. */
    public function disconnect(OwnNumber $own): RedirectResponse
    {
        $connection = OwnNumber::current() ?? abort(404);
        if ($connection->isConnected()) {
            $own->unsubscribe($connection);
        }
        $connection->delete();
        Audit::record('whatsapp.own_number.disconnected', (string) ($connection->display_phone ?? $connection->phone_number_id), [
            'waba_id' => $connection->waba_id, 'phone_number_id' => $connection->phone_number_id,
        ]);

        return back()->with('ok', __('Your number is disconnected. Messages go out from the EgHub number again.'));
    }
}
