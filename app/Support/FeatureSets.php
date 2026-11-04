<?php

namespace App\Support;

/**
 * Plan features that open the same screens together, named once for the `feature:` middleware of routes/dashboard.php
 * (any one of them opens the group). A set lives here when more than one route group uses it.
 */
final class FeatureSets
{
    /** Delivery screens: zones and drivers come with the delivery pack or with online ordering. */
    public const DELIVERY = 'delivery_zones|online_ordering';

    /** Discount codes: campaign coupons and loyalty vouchers share one page. */
    public const DISCOUNT_CODES = 'loyalty_stamps|campaigns';

    /** The advanced cards (cards pack / Business): cashback, prepaid packages, membership tiers. */
    public const ADVANCED_CARDS = 'cashback|prepaid_packages|membership';
}
