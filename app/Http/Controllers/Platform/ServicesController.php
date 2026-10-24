<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\ServiceRequest;
use App\Notifications\ServiceRequested;
use App\Services\Qr;
use App\Support\SiteServices;
use App\Support\StandStyles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Marketing site: EgWeb's one-time services (/services) and the request form, emailed to the platform admins. */
class ServicesController extends Controller
{
    /** The stand studio's sample QR opens the EgHub site: a real restaurant gets its own menu QR on the printed stand. */
    private const SAMPLE_URL = 'https://eghub.app';

    public function index(Request $request): View
    {
        $whatsapp = (string) PlatformSetting::get('support_whatsapp');
        $prefill = $this->standPrefill($request);
        $print = SiteServices::find('print');

        return view('landing.services', [
            'services' => SiteServices::all(),
            'selected' => $prefill ? 'print' : (in_array($request->query('service'), SiteServices::keys(), true) ? $request->query('service') : null),
            'whatsappUrl' => trim($whatsapp) !== '' ? 'https://wa.me/'.whatsapp_number($whatsapp) : null,
            'prefill' => $prefill ?? ['restaurant' => '', 'notes' => ''],
            'sampleQr' => Qr::svg(self::SAMPLE_URL, 300, '#000000', 2),
            'standPrices' => $print ? array_values(array_filter($print['items'], fn (array $item) => $item[0] === __('Acrylic QR table stand'))) : [],
            'printService' => $print,
        ]);
    }

    /**
     * "Order printed stands" from the stand studio: the request form opens on the print service with the chosen design
     * written into the details.
     *
     * @return array{restaurant:string, notes:string}|null
     */
    private function standPrefill(Request $request): ?array
    {
        $style = (string) $request->query('stand');
        if (! in_array($style, StandStyles::keys(), true)) {
            return null;
        }
        $accent = (string) $request->query('accent');
        $table = mb_substr(trim((string) $request->query('table')), 0, 20);

        return [
            'restaurant' => mb_substr(trim((string) $request->query('restaurant')), 0, 120),
            'notes' => __('QR table stands, :style design, accent color :accent.', [
                'style' => StandStyles::label($style), 'accent' => is_hex_color($accent) ? strtoupper($accent) : '-',
            ]).($table !== '' ? ' '.__('Sample table number: :table.', ['table' => $table]) : ''),
        ];
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
        Notification::send(PlatformAdmin::notifiable('services.manage'), new ServiceRequested($serviceRequest));

        return redirect()->route('services')->with('service_sent', $serviceRequest->serviceName())->withFragment('request');
    }
}
