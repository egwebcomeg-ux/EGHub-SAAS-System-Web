<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use App\Services\Seo\SiteShowcase;
use App\Services\Seo\SocialProof;
use App\Support\SiteModules;
use Illuminate\View\View;

/** Marketing site: home, pricing, terms and privacy. Plans, add-ons and prices come from the database. */
class LandingController extends Controller
{
    public function home(): View
    {
        return view('landing.home', SiteModules::pricing() + [
            'showcaseLogos' => SiteShowcase::logos(),
            'proofPlaces' => SocialProof::places(),
            'proofCounters' => SocialProof::counters(),
            'testimonials' => Testimonial::query()->live()->with('tenant')->limit(Testimonial::HOME_LIMIT)->get(),
        ]);
    }

    /** All plans side by side, with the add-ons. */
    public function pricing(): View
    {
        return view('landing.pricing', SiteModules::pricing());
    }

    public function terms(): View
    {
        return view('landing.terms');
    }

    public function privacy(): View
    {
        return view('landing.privacy');
    }
}
