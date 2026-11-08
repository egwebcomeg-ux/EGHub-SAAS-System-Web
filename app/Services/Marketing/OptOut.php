<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Notify\GuestNotifier;
use App\Services\Settings;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * «إيقاف العروض»: the quick-reply button under every campaign and automation message. Its payload names the guest by
 * their random card token (never an id); the tap must come from the guest's own mobile. The guest's restaurant is
 * opened only to mark them (whatsapp_opt_out, which campaigns and automations already skip) and to answer them once.
 */
final class OptOut
{
    private const PAYLOAD = '/^wo:([A-Za-z0-9]{8,64})$/';

    public function __construct(private WhatsApp $whatsapp) {}

    /** @return array{stop: string}|array{} */
    public static function payloads(Customer $customer): array
    {
        return $customer->card_token ? ['stop' => 'wo:'.$customer->card_token] : [];
    }

    /**
     * A tap from the inbound webhook (no tenant context). A tap that reached a restaurant's own number ($via) only
     * ever stops that restaurant's offers. Returns the guest stopped, or null when the tap was not ours or not theirs.
     */
    public function handleTap(string $from, string $payload, ?WhatsAppConnection $via = null): ?Customer
    {
        if (! preg_match(self::PAYLOAD, trim($payload), $m) || tenancy()->initialized) {
            return null;
        }
        $found = Customer::query()->withoutGlobalScopes()->where('card_token', $m[1])
            ->when($via, fn ($q) => $q->where('tenant_id', $via->tenant_id))->first(['id', 'tenant_id', 'phone']);
        $sender = normalize_phone($from);
        if (! $found || $sender === null || $sender !== normalize_phone((string) $found->phone)) {
            Log::info('whatsapp opt-out ignored', ['known_guest' => $found !== null]);

            return null;
        }
        $tenant = Tenant::query()->find($found->tenant_id);
        if (! $tenant) {
            return null;
        }

        tenancy()->initialize($tenant);
        Settings::flush();
        try {
            $customer = Customer::query()->find($found->id);
            if (! $customer) {
                return null;
            }
            if (! $customer->whatsapp_opt_out) {
                $customer->update(['whatsapp_opt_out' => true]);
                Audit::record('customer.opted_out', (string) $customer->id, ['via' => 'whatsapp']);
            }
            if (Cache::add('wa:opt-out-reply:'.$customer->id, 1, 3600)) {
                $en = WhatsApp::locale() === 'en';
                $name = GuestNotifier::senderName();
                $this->whatsapp->reply((string) $customer->phone, $en
                    ? "Done ✋ You will not get offers from {$name} any more. Your orders and loyalty card keep working as usual."
                    : "تمام ✋ مش هتوصلك عروض تانية من {$name}. طلباتك وكارت الولاء شغالين عادي.", $customer->id, $via ? 'own' : 'platform');
            }

            return $customer;
        } catch (\Throwable $e) {
            report($e); // Meta always gets its 200

            return null;
        } finally {
            tenancy()->end();
            Settings::flush();
            Entitlements::flush();
        }
    }
}
