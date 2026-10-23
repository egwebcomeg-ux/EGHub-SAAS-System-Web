<?php

namespace App\Services\Seo;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Support\LegalDetails;
use App\Support\SocialLinks;
use Illuminate\Support\Collection;

/**
 * schema.org JSON-LD of the marketing site: Organization + WebSite on the home page, SoftwareApplication with one
 * Offer per public plan on /pricing (prices from the plans table; a plan sold through sales, Enterprise, has no
 * price). Rendered with RestaurantProfile::json().
 */
final class PlatformSchema
{
    /** @return array<string, mixed> */
    public static function organization(): array
    {
        $legalName = collect(LegalDetails::lines())->firstWhere('key', 'legal_entity')['value'] ?? null;
        $email = trim((string) (PlatformSetting::get('contact_email') ?: PlatformSetting::get('support_email')));
        $whatsapp = trim((string) (PlatformSetting::get('support_whatsapp') ?: PlatformSetting::get('sales_whatsapp')));
        $contact = array_filter([
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'email' => $email !== '' ? $email : null,
            'telephone' => $whatsapp !== '' ? '+'.whatsapp_number($whatsapp) : null,
            'availableLanguage' => ['ar', 'en'],
        ]);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => platform_url(null, '/').'#organization',
            'name' => 'EgMenu',
            'legalName' => $legalName,
            'url' => platform_url(null, '/'),
            'logo' => versioned_asset('icons/egmenu-512.png'),
            'sameAs' => array_column(SocialLinks::all(), 'url') ?: null,
            'contactPoint' => isset($contact['email']) || isset($contact['telephone']) ? $contact : null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => platform_url(null, '/').'#website',
            'name' => 'EgMenu',
            'url' => platform_url(null, '/'),
            'inLanguage' => ['ar', 'en'],
            'publisher' => ['@id' => platform_url(null, '/').'#organization'],
        ];
    }

    /**
     * @param  Collection<int, Plan>  $plans  the public plans, in display order
     * @return array<string, mixed>
     */
    public static function softwareApplication(Collection $plans): array
    {
        $offers = $plans->map(fn (Plan $plan) => $plan->isContactSales()
            ? ['@type' => 'Offer', 'name' => $plan->displayName(), 'url' => route('pricing'), 'description' => __('Priced for your branches and screens')]
            : [
                '@type' => 'Offer',
                'name' => $plan->displayName(),
                'price' => number_format((float) $plan->monthly_price, 2, '.', ''),
                'priceCurrency' => 'EGP',
                'url' => route('pricing'),
                'priceSpecification' => [
                    '@type' => 'UnitPriceSpecification',
                    'price' => number_format((float) $plan->monthly_price, 2, '.', ''),
                    'priceCurrency' => 'EGP',
                    'unitCode' => 'MON',
                    'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'MON'],
                ],
            ])->values()->all();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'EgMenu',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => route('pricing'),
            'description' => __('A free QR menu for cafes and restaurants in Egypt, with table and online ordering without commission, a loyalty card and a print-ready menu when you need them.'),
            'publisher' => ['@id' => platform_url(null, '/').'#organization'],
            'offers' => $offers,
        ];
    }
}
