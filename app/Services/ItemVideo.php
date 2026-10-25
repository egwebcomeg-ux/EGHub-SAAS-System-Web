<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Short videos of menu items (feature full_branding): an uploaded MP4 / WebM up to 15 MB, stored as-is under the
 * restaurant's folder (tenants/{id}/videos/{uuid}.mp4), or a YouTube / Instagram / TikTok link shown as a lazy embed.
 * The menu plays uploads muted, looped and inline; embeds load only when the guest taps play, muted where possible.
 */
final class ItemVideo
{
    public const FEATURE = 'full_branding';

    public const MAX_KB = 15360;

    /**
     * Magic bytes of the accepted containers: ISO-BMFF ("ftyp" at offset 4) and Matroska/WebM (EBML header). The file
     * is kept under a random name with the extension of what it really is, never the uploaded name. TV screens store
     * longer videos (up to TvMedia::MAX_VIDEO_KB) in their own folder.
     */
    public function store(UploadedFile $file, int $maxKb = self::MAX_KB, string $dir = 'videos', ?string $error = null): string
    {
        $fail = fn () => throw ValidationException::withMessages(['video' => $error ?? __('Upload an MP4 or WebM video up to 15 MB.')]);
        if (! $file->isValid() || $file->getSize() > $maxKb * 1024 || $file->getSize() < 64) {
            $fail();
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        $ext = match (true) {
            substr($head, 4, 4) === 'ftyp' => 'mp4',
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'webm',
            default => null,
        };
        if (! $ext || ! in_array($file->getMimeType(), ['video/mp4', 'video/webm', 'video/quicktime', 'audio/mp4', 'application/octet-stream'], true)) {
            $fail();
        }
        $path = 'tenants/'.tenant()->getTenantKey()."/$dir/".Str::uuid().".$ext";
        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

        return $path;
    }

    public function delete(?string $path, string $dir = 'videos'): void
    {
        if ($path && str_starts_with($path, 'tenants/'.tenant()->getTenantKey()."/$dir/")) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The embed of a YouTube / Instagram / TikTok link, or null when the link is not one of those.
     *
     * @return array{provider:string, embed:string}|null
     */
    public static function embed(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
            return ['provider' => 'youtube', 'embed' => "https://www.youtube-nocookie.com/embed/{$m[1]}?autoplay=1&mute=1&loop=1&playlist={$m[1]}&playsinline=1&rel=0"];
        }
        if (preg_match('#^https?://(?:www\.)?instagram\.com/(?:[A-Za-z0-9_.]+/)?(p|reel|reels|tv)/([A-Za-z0-9_-]+)#i', $url, $m)) {
            $kind = strtolower($m[1]) === 'p' ? 'p' : 'reel';

            return ['provider' => 'instagram', 'embed' => "https://www.instagram.com/$kind/{$m[2]}/embed"];
        }
        if (preg_match('#^https?://(?:www\.|m\.)?tiktok\.com/@[^/]+/video/(\d{6,25})#i', $url, $m)) {
            return ['provider' => 'tiktok', 'embed' => "https://www.tiktok.com/embed/v2/{$m[1]}"];
        }

        return null;
    }
}
