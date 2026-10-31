<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * FAKE_NOW (a UTC time) runs the suite at that moment, e.g. FAKE_NOW="2026-10-30 23:00" php artisan test: after
     * midnight in Cairo the restaurant's day is not UTC's, which is when a test mixing the two breaks. The clock is
     * frozen there, so a test that needs time to move (a post published "now", a backup older than the next one) fails
     * under it and only under it.
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAKE_NOW')) {
            $this->travelTo(Carbon::parse(getenv('FAKE_NOW'), 'UTC'));
        }
    }
}
