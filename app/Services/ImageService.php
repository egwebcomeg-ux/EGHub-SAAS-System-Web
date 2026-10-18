<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Upload handling with GD (idea from Davaya). Every accepted image is fully decoded and re-encoded to WebP, which
 * strips EXIF/GPS metadata and anything hidden inside the original file, and is stored under the restaurant's own
 * folder with a random name: storage/app/public/tenants/{tenant}/{dir}/{uuid}.webp → /storage/tenants/...
 */
final class ImageService
{
    private const ALLOWED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    private const MAX_PIXELS = 24_000_000;

    /** profile => [dir, max width, max height, square crop, lossless] */
    private const PROFILES = [
        'item' => ['items', 900, 900, false],
        'category' => ['categories', 900, 600, false],
        // logos stay lossless (sharp edges, full alpha), so a transparent logo looks crisp on dark templates
        'logo' => ['brand', 512, 512, false, true],
        'cover' => ['brand', 1600, 700, false],
        'banner' => ['banners', 1600, 700, false],
        'post' => ['blog', 1600, 900, false],            // platform blog covers (no tenant)
        'testimonial' => ['testimonials', 400, 400, false], // owner photos on the marketing site (no tenant)
    ];

    /** @return string path on the public disk, e.g. tenants/my-cafe/items/uuid.webp */
    public function store(UploadedFile $file, string $profile): string
    {
        [$dir, $maxW, $maxH] = self::PROFILES[$profile] ?? throw new \InvalidArgumentException("Unknown profile $profile");
        $src = $this->decode($file);

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagewebp($dst, null, (self::PROFILES[$profile][4] ?? false) ? IMG_WEBP_LOSSLESS : 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($dst);

        $path = self::base()."/$dir/".Str::uuid().'.webp';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** Deletes a stored image, but only one inside the current restaurant's folder (or the platform folder outside tenancy). */
    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, self::base().'/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** Images of the current restaurant live under tenants/{id}; platform images (the blog) under platform/. */
    private static function base(): string
    {
        return tenant() ? 'tenants/'.tenant()->getTenantKey() : 'platform';
    }

    private function decode(UploadedFile $file): \GdImage
    {
        $fail = fn () => throw ValidationException::withMessages(['image' => __('Upload a JPG, PNG or WebP image up to 5 MB.')]);

        if (! $file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            $fail();
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], self::ALLOWED, true) || $info[0] * $info[1] > self::MAX_PIXELS || min($info[0], $info[1]) < 20) {
            $fail();
        }
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            $fail();
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        return $img;
    }
}
