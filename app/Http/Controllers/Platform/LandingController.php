<?php

namespace App\Http\Controllers\Platform;

use App\Billing\CustomPlan;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Testimonial;
use App\Services\Seo\EgwebClients;
use App\Services\Seo\SiteShowcase;
use App\Services\Seo\SocialProof;
use App\Support\SiteModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Marketing site: home, pricing, terms, privacy and the refund policy. Plans, add-ons and prices come from the database. */
class LandingController extends Controller
{
    public function home(): View
    {
        return view('landing.home', SiteModules::pricing() + [
            'logoStrip' => self::logoStrip(),
            'proofPlaces' => SocialProof::places(),
            'proofCounters' => SocialProof::counters(),
            'testimonials' => Testimonial::query()->live()->with('tenant')->limit(Testimonial::HOME_LIMIT)->get(),
        ]);
    }

    /**
     * The one moving strip of logos: the EgHub places EgWeb turned on first, then EgWeb's own clients (a place in both
     * shows once), from SiteShowcase::MIN_LOGOS logos up. The title names both, so no EgWeb client is presented as an
     * EgHub user.
     *
     * @return array{logos: list<array<string, mixed>>, places: int, clients: int}
     */
    private static function logoStrip(): array
    {
        $places = array_map(fn (array $p) => ['name' => tr($p['name'], $p['name_en']), 'logo' => $p['logo'], 'url' => $p['url']], SiteShowcase::places());
        $seen = array_map(fn (array $p) => mb_strtolower(trim($p['name'])), $places);
        $clients = array_values(array_map(fn (array $c) => $c + ['rel' => 'noopener nofollow'], array_filter(EgwebClients::active(), fn (array $c) => ! in_array(mb_strtolower(trim($c['name'])), $seen, true))));
        $logos = array_slice([...$places, ...$clients], 0, SiteShowcase::MAX_LOGOS);

        return count($logos) >= SiteShowcase::MIN_LOGOS
            ? ['logos' => $logos, 'places' => count($places), 'clients' => count($clients)]
            : ['logos' => [], 'places' => 0, 'clients' => 0];
    }

    /** All plans side by side, with the add-ons. */
    public function pricing(): View
    {
        $custom = CustomPlan::plan();

        return view('landing.pricing', SiteModules::pricing() + [
            'custom' => $custom ? ['catalog' => CustomPlan::catalog($custom), 'quote' => CustomPlan::quote([], $custom)] : null,
        ]);
    }

    /** «صمم باقتك» on the pricing page: the total of the ticked modules, rendered, and the sign-up link carrying them. */
    public function customPlan(Request $request): JsonResponse
    {
        $plan = CustomPlan::plan() ?? abort(404);
        $data = $request->validate(['modules' => 'array|max:40', 'modules.*' => 'string|max:40']);
        $quote = CustomPlan::quote($data['modules'] ?? [], $plan);

        return response()->json([
            'html' => view('partials.custom-plan-summary', ['quote' => $quote, 'mode' => 'public'])->render(),
            'slugs' => $quote['slugs'],
            'monthly' => $quote['monthly'],
            'register' => route('register', array_filter(['modules' => implode(',', $quote['slugs'])])),
        ]);
    }

    public function terms(): View
    {
        return view('landing.terms');
    }

    public function privacy(): View
    {
        return view('landing.privacy');
    }

    /** Cancellation and refunds of subscriptions, with the support contacts from admin › settings. */
    public function refund(): View
    {
        $whatsapp = trim((string) (PlatformSetting::get('support_whatsapp') ?: PlatformSetting::get('sales_whatsapp')));

        return view('landing.refund', [
            'contactEmail' => trim((string) (PlatformSetting::get('support_email') ?: PlatformSetting::get('contact_email'))),
            'contactWhatsApp' => $whatsapp,
            'contactWhatsAppUrl' => $whatsapp !== '' ? 'https://wa.me/'.whatsapp_number($whatsapp) : null,
        ]);
    }
}
