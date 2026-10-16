<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\ServiceRequest;
use App\Notifications\ServiceRequested;
use App\Support\SiteServices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Marketing site: EgWeb's one-time services (/services) and the request form, emailed to the platform admins. */
class ServicesController extends Controller
{
    public function index(Request $request): View
    {
        $whatsapp = (string) PlatformSetting::get('support_whatsapp');

        return view('landing.services', [
            'services' => SiteServices::all(),
            'selected' => in_array($request->query('service'), SiteServices::keys(), true) ? $request->query('service') : null,
            'whatsappUrl' => trim($whatsapp) !== '' ? 'https://wa.me/'.whatsapp_number($whatsapp) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', Rule::in(SiteServices::keys())],
            'name' => 'required|string|max:100',
            'restaurant' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:190',
            'quantity' => 'nullable|integer|min:1|max:100000',
            'notes' => 'nullable|string|max:1000',
            'website' => 'prohibited', // honeypot: hidden from people, filled by bots
        ]);
        $phone = normalize_phone($data['phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')])->withFragment('request');
        }

        $serviceRequest = ServiceRequest::query()->create([
            'service' => $data['service'], 'name' => trim($data['name']), 'restaurant' => trim($data['restaurant']), 'phone' => $phone,
            'email' => $data['email'] ?? null, 'quantity' => $data['quantity'] ?? null,
            'notes' => isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null,
            'locale' => app()->getLocale(), 'ip' => client_ip(),
        ]);
        Notification::send(PlatformAdmin::query()->get(), new ServiceRequested($serviceRequest));

        return redirect()->route('services')->with('service_sent', $serviceRequest->serviceName())->withFragment('request');
    }
}
