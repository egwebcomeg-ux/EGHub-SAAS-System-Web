<?php

namespace App\Services\Geocoding;

use RuntimeException;

/** The provider did not answer this time (busy, rate limited, timed out, an error): try again later, never cached. */
final class GeocoderUnavailable extends RuntimeException {}
