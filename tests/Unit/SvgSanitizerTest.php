<?php

namespace Tests\Unit;

use App\Services\BrandAssets;
use PHPUnit\Framework\TestCase;

/** Brand SVGs keep their drawing and lose everything that could run or fetch. */
class SvgSanitizerTest extends TestCase
{
    public function test_scripts_handlers_and_outside_references_are_stripped(): void
    {
        $dirty = <<<'SVG'
            <?xml version="1.0"?>
            <?xml-stylesheet href="https://evil.test/x.css"?>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">
              <defs><clipPath id="c"><path d="M0 0H10V10Z"/></clipPath></defs>
              <script>alert(2)</script>
              <foreignObject><iframe src="https://evil.test"/></foreignObject>
              <style>@import url(https://evil.test/a.css);</style>
              <a href="javascript:alert(3)"><circle cx="5" cy="5" r="4" fill="#9F2241" onclick="alert(4)"/></a>
              <image href="https://evil.test/track.png" xlink:href="https://evil.test/track.png" width="1" height="1"/>
              <image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>
              <use xlink:href="#c"/>
              <rect width="2" height="2" style="fill:url(https://evil.test/p)" clip-path="url(#c)"/>
              <set attributeName="href" to="javascript:alert(5)"/>
            </svg>
            SVG;

        $clean = BrandAssets::sanitize($dirty);

        $this->assertNotNull($clean);
        foreach (['<script', 'alert(', 'onload', 'onclick', 'foreignObject', 'iframe', '@import', 'evil.test', 'javascript:', '<set', 'xml-stylesheet'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $clean);
        }
        // the drawing stays: the circle (unwrapped from its link), the clip and local references, embedded raster data
        $this->assertStringContainsString('<circle cx="5" cy="5" r="4" fill="#9F2241"/>', $clean);
        $this->assertStringContainsString('clip-path="url(#c)"', $clean);
        $this->assertStringContainsString('xlink:href="#c"', $clean);
        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo=', $clean);
    }

    public function test_entities_and_non_svg_files_are_refused(): void
    {
        $this->assertNull(BrandAssets::sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
        $this->assertNull(BrandAssets::sanitize('<html><body>hi</body></html>'));
        $this->assertNull(BrandAssets::sanitize('not xml at all'));
    }
}
