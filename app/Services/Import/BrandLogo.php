<?php

namespace App\Services\Import;

use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Cuts the restaurant's logo out of an uploaded menu (the box the AI pointed at) and finds the main colours when the
 * AI gave none, with PHP GD only. A PDF page is first drawn to PNG by Ghostscript when the server has it (`gs`; a
 * missing binary just means no logo / colours from a PDF). Every step degrades to "nothing found", never an error:
 * the menu import must not fail because of its looks.
 */
class BrandLogo
{
    private const MAX_PIXELS = 24_000_000;

    /** The Ghostscript binary, or null (e.g. a Windows development machine without it). */
    public function ghostscript(): ?string
    {
        $finder = new ExecutableFinder;
        foreach (PHP_OS_FAMILY === 'Windows' ? ['gswin64c', 'gswin32c'] : ['gs'] as $name) {
            if ($path = $finder->find($name)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Fills the draft's brand from the files: the cut-out logo (stored with ImageService under the restaurant's
     * folder) and, when the AI saw no colours, the dominant ones of the first page.
     *
     * @param  list<array{mime:string, bytes:string}>  $files  the uploads (photos in order, or one PDF)
     */
    public function enrich(?array $brand, array $files): ?array
    {
        if ($files === []) {
            return $brand;
        }
        $brand ??= ['primary' => null, 'secondary' => null, 'background' => null, 'text' => null, 'font_style' => null,
            'font' => null, 'language' => null, 'logo_box' => null, 'logo' => null];
        $box = $brand['logo_box'] ?? null;
        $needColors = ($brand['primary'] ?? null) === null && ($brand['secondary'] ?? null) === null;
        $pages = [];
        $page = function (int $n) use ($files, &$pages): ?\GdImage {
            return $pages[$n] ??= $this->page($files, $n);
        };

        try {
            if ($box !== null && ($image = $page($box['page'])) !== null && ($logo = $this->cut($image, $box)) !== null) {
                $brand['logo'] = $this->store($logo);
            }
            if ($needColors && ($image = $page(1)) !== null) {
                foreach ($this->dominantColors($image) as $key => $color) {
                    if ($color !== null && ($brand[$key] ?? null) === null) {
                        $brand[$key] = $color;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('menu import brand step skipped', ['error' => $e->getMessage()]);
        }
        // a page background alone (a blank or grey scan) is not a style worth offering
        $useful = array_filter($brand, fn ($v, $k) => $v !== null && ! in_array($k, ['logo_box', 'background', 'text'], true), ARRAY_FILTER_USE_BOTH);

        return $useful === [] ? null : $brand;
    }

    /** Page $n (1-based) of the uploads as a GD image: the n-th photo, or the n-th page of the PDF via Ghostscript. */
    public function page(array $files, int $n): ?\GdImage
    {
        $pdf = collect($files)->first(fn ($f) => $f['mime'] === 'application/pdf');
        if ($pdf !== null) {
            $png = $this->renderPdfPage($pdf['bytes'], $n);

            return $png !== null ? $this->decode($png) : null;
        }
        $file = $files[$n - 1] ?? null;

        return $file !== null ? $this->decode($file['bytes']) : null;
    }

    /** One PDF page drawn to PNG bytes at 150 dpi (Ghostscript, no shell, safe mode, 40 s at most), or null. */
    public function renderPdfPage(string $pdfBytes, int $page): ?string
    {
        $gs = $this->ghostscript();
        if ($gs === null || $page < 1 || $page > 50) {
            return null;
        }
        $dir = storage_path('app/private/tmp');
        @mkdir($dir, 0775, true);
        $in = $dir.'/brand-'.bin2hex(random_bytes(8)).'.pdf';
        $out = substr($in, 0, -4).'.png';
        try {
            file_put_contents($in, $pdfBytes);
            $result = Process::timeout(40)->run([$gs, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET', '-sDEVICE=png16m', '-r150',
                '-dFirstPage='.$page, '-dLastPage='.$page, '-sOutputFile='.$out, $in]);

            return $result->successful() && is_file($out) && filesize($out) > 0 ? (string) file_get_contents($out) : null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** Crops the box (fractions of the page), knocks a plain background out to transparency and trims it; null if empty. */
    public function cut(\GdImage $page, array $box): ?\GdImage
    {
        $w = imagesx($page);
        $h = imagesy($page);
        $x = (int) floor($box['x'] * $w);
        $y = (int) floor($box['y'] * $h);
        $cw = (int) min($w - $x, ceil($box['w'] * $w));
        $ch = (int) min($h - $y, ceil($box['h'] * $h));
        if ($cw < 16 || $ch < 16) {
            return null;
        }
        // a logo never needs more than ~1000 px: big boxes are scaled down first (the pixel loops stay fast)
        $scale = min(1, 1000 / max($cw, $ch));
        $nw = max(1, (int) round($cw * $scale));
        $nh = max(1, (int) round($ch * $scale));
        $crop = imagecreatetruecolor($nw, $nh);
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        imagecopyresampled($crop, $page, 0, 0, $x, $y, $nw, $nh, $cw, $ch);

        return $this->trim($this->knockOut($crop));
    }

    /**
     * When the four corners share one colour (paper, a plain band), every pixel close to it becomes transparent,
     * with a soft edge so the outline is not jagged. A busy background (a photo) is kept as it is.
     */
    public function knockOut(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $patch = max(1, (int) round(min($w, $h) * 0.06));
        $corners = [[0, 0], [$w - $patch, 0], [0, $h - $patch], [$w - $patch, $h - $patch]];
        $samples = array_map(fn ($c) => $this->average($img, $c[0], $c[1], $patch), $corners);
        $bg = [
            array_sum(array_column($samples, 0)) / 4, array_sum(array_column($samples, 1)) / 4, array_sum(array_column($samples, 2)) / 4,
        ];
        foreach ($samples as $s) {
            if ($this->distance($s, $bg) > 28) {
                return $img;
            }
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $rgba = imagecolorat($img, $px, $py);
                $c = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
                $d = $this->distance($c, $bg);
                if ($d < 60) {
                    // 0..30 fully transparent, 30..60 fading in
                    $alpha = $d <= 30 ? 127 : (int) round(127 * (60 - $d) / 30);
                    imagesetpixel($img, $px, $py, imagecolorallocatealpha($img, $c[0], $c[1], $c[2], max($alpha, ($rgba >> 24) & 0x7F)));
                }
            }
        }

        return $img;
    }

    /** Drops the transparent margins (keeping a small padding); null when nothing is left. */
    public function trim(\GdImage $img): ?\GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 110) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0 || ($maxX - $minX) < 8 || ($maxY - $minY) < 8) {
            return null;
        }
        $pad = (int) round(max($maxX - $minX, $maxY - $minY) * 0.04);
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $nw = min($w, $maxX + $pad + 1) - $minX;
        $nh = min($h, $maxY + $pad + 1) - $minY;
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopy($out, $img, 0, 0, $minX, $minY, $nw, $nh);

        return $out;
    }

    /**
     * The colours of a page from a 64 px thumbnail: the most common one is the background, the most common
     * saturated ones the brand colours (primary = the most used, secondary = a darker or different one).
     *
     * @return array{primary:?string, secondary:?string, background:?string}
     */
    public function dominantColors(\GdImage $img): array
    {
        $tw = 64;
        $th = max(1, (int) round(imagesy($img) * $tw / max(1, imagesx($img))));
        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, imagesx($img), imagesy($img));
        $all = [];
        $vivid = [];
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                // 32 levels per channel, keyed by the bucket; the bucket keeps a running sum for its average colour
                $key = intdiv($c[0], 32).'-'.intdiv($c[1], 32).'-'.intdiv($c[2], 32);
                $all[$key] = $this->add($all[$key] ?? null, $c);
                $max = max($c);
                $min = min($c);
                $saturation = $max === 0 ? 0 : ($max - $min) / $max;
                if ($saturation > 0.35 && $max > 50) {
                    $vivid[$key] = $this->add($vivid[$key] ?? null, $c);
                }
            }
        }
        $byCount = fn (array $a, array $b) => $b[3] <=> $a[3];
        uasort($all, $byCount);
        uasort($vivid, $byCount);
        $avg = fn (array $s) => sprintf('#%02X%02X%02X', $s[0] / $s[3], $s[1] / $s[3], $s[2] / $s[3]);
        $total = $tw * $th;
        $background = $all !== [] ? $avg(reset($all)) : null;
        $brandish = array_values(array_filter($vivid, fn ($s) => $s[3] >= $total * 0.004));
        $primary = isset($brandish[0]) ? $avg($brandish[0]) : null;
        $secondary = null;
        foreach (array_slice($brandish, 1) as $s) {
            $candidate = $avg($s);
            if ($this->distance($this->rgb($candidate), $this->rgb((string) $primary)) > 80) {
                $secondary = $candidate;
                break;
            }
        }

        return ['primary' => $primary, 'secondary' => $secondary, 'background' => $background];
    }

    /** Stores the logo as a lossless WebP through ImageService (re-encoded, under tenants/{id}/brand). */
    public function store(\GdImage $logo): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        try {
            imagepng($logo, $tmp);

            return app(ImageService::class)->store(new UploadedFile($tmp, 'logo.png', 'image/png', null, true), 'logo');
        } finally {
            @unlink($tmp);
        }
    }

    private function decode(string $bytes): ?\GdImage
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        return $img;
    }

    /** @return array{0:float, 1:float, 2:float} */
    private function average(\GdImage $img, int $x0, int $y0, int $size): array
    {
        $sum = [0, 0, 0];
        $n = 0;
        for ($y = $y0; $y < $y0 + $size; $y++) {
            for ($x = $x0; $x < $x0 + $size; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $sum[0] += ($rgb >> 16) & 0xFF;
                $sum[1] += ($rgb >> 8) & 0xFF;
                $sum[2] += $rgb & 0xFF;
                $n++;
            }
        }

        return [$sum[0] / $n, $sum[1] / $n, $sum[2] / $n];
    }

    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @return array{0:int, 1:int, 2:int, 3:int} running [r, g, b, count] */
    private function add(?array $sum, array $c): array
    {
        $sum ??= [0, 0, 0, 0];

        return [$sum[0] + $c[0], $sum[1] + $c[1], $sum[2] + $c[2], $sum[3] + 1];
    }

    /** @return array{0:int, 1:int, 2:int} */
    private function rgb(string $hex): array
    {
        return array_map('intval', sscanf(ltrim($hex, '#'), '%02x%02x%02x') ?: [0, 0, 0]);
    }
}
