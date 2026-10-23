<?php

namespace Tests\Unit;

use App\Services\Import\BrandLogo;
use PHPUnit\Framework\TestCase;

/** The GD pipeline that cuts a logo out of an uploaded menu page, and the dominant-colour fallback. */
class BrandLogoTest extends TestCase
{
    /** A cream page with a burgundy mark at (300..379, 100..149). */
    private function page(): \GdImage
    {
        $page = imagecreatetruecolor(800, 1000);
        imagefill($page, 0, 0, imagecolorallocate($page, 253, 251, 230));
        imagefilledrectangle($page, 300, 100, 379, 149, imagecolorallocate($page, 159, 34, 65));

        return $page;
    }

    public function test_the_box_is_cut_its_plain_background_made_transparent_and_trimmed(): void
    {
        $logo = (new BrandLogo)->cut($this->page(), ['page' => 1, 'x' => 0.25, 'y' => 0.05, 'w' => 0.5, 'h' => 0.2]);

        $this->assertNotNull($logo);
        // the 400 × 200 box shrinks to the 80 × 50 mark plus a small padding
        $this->assertLessThanOrEqual(90, imagesx($logo));
        $this->assertGreaterThanOrEqual(80, imagesx($logo));
        $this->assertLessThanOrEqual(60, imagesy($logo));
        $corner = imagecolorat($logo, 0, 0);
        $this->assertSame(127, ($corner >> 24) & 0x7F); // the cream paper is gone
        $centre = imagecolorat($logo, intdiv(imagesx($logo), 2), intdiv(imagesy($logo), 2));
        $this->assertSame(0, ($centre >> 24) & 0x7F);
        $this->assertSame([159, 34, 65], [($centre >> 16) & 0xFF, ($centre >> 8) & 0xFF, $centre & 0xFF]);
    }

    public function test_an_empty_or_tiny_box_gives_no_logo(): void
    {
        $logos = new BrandLogo;
        $this->assertNull($logos->cut($this->page(), ['page' => 1, 'x' => 0.0, 'y' => 0.6, 'w' => 0.5, 'h' => 0.3])); // only paper
        $this->assertNull($logos->cut($this->page(), ['page' => 1, 'x' => 0.5, 'y' => 0.5, 'w' => 0.01, 'h' => 0.01]));
    }

    public function test_a_busy_background_is_kept(): void
    {
        $img = imagecreatetruecolor(100, 100);
        imagefilledrectangle($img, 0, 0, 49, 99, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 50, 0, 99, 99, imagecolorallocate($img, 0, 0, 255));

        $out = (new BrandLogo)->knockOut($img);
        $this->assertSame(0, (imagecolorat($out, 0, 0) >> 24) & 0x7F);
    }

    public function test_dominant_colours_find_the_paper_and_the_brand_colour(): void
    {
        $page = imagecreatetruecolor(300, 300);
        imagefill($page, 0, 0, imagecolorallocate($page, 253, 251, 230));
        imagefilledrectangle($page, 0, 0, 299, 59, imagecolorallocate($page, 159, 34, 65));

        $colors = (new BrandLogo)->dominantColors($page);
        $this->assertSame('#FDFBE6', $colors['background']);
        $this->assertSame('#9F2241', $colors['primary']);
    }

    public function test_a_pdf_page_is_drawn_with_ghostscript_when_the_server_has_it(): void
    {
        $logos = new BrandLogo;
        if ($logos->ghostscript() === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed here.');
        }
        // a one-page PDF with a filled rectangle
        $stream = '0.62 0.13 0.25 rg 100 600 200 100 re f';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n".implode('', array_map(fn ($o) => sprintf("%010d 00000 n \n", $o), $offsets));
        $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        $png = $logos->renderPdfPage($pdf, 1);
        $this->assertNotNull($png);
        $this->assertSame('image/png', getimagesizefromstring($png)['mime']);
        $this->assertNull($logos->renderPdfPage($pdf, 0));
    }
}
