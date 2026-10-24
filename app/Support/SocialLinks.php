<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * EgHub's own social media accounts, set in the super admin (Payment settings → Social media) and shown as icons in
 * the marketing site footer. Empty or non-https links are never shown.
 */
final class SocialLinks
{
    /** network => [label, 24px filled icon path] */
    public const NETWORKS = [
        'facebook' => ['Facebook', 'M14 8h3V4h-3c-2.8 0-5 2.2-5 5v2H7v4h2v9h4v-9h3l1-4h-4V9c0-.6.4-1 1-1z'],
        'instagram' => ['Instagram', 'M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm4.9-8a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0zM12 3c-2.4 0-2.7 0-3.7.1-3.3.1-5 1.8-5.2 5.1C3 9.3 3 9.6 3 12s0 2.7.1 3.7c.1 3.3 1.8 5 5.1 5.2 1 .1 1.3.1 3.8.1s2.7 0 3.7-.1c3.3-.1 5-1.8 5.2-5.1.1-1 .1-1.3.1-3.8s0-2.7-.1-3.7c-.1-3.3-1.8-5-5.1-5.2C14.7 3 14.4 3 12 3zm0 1.6c2.4 0 2.6 0 3.6.1 2.4.1 3.6 1.3 3.7 3.7.1 1 .1 1.2.1 3.6s0 2.6-.1 3.6c-.1 2.4-1.3 3.6-3.7 3.7-1 .1-1.2.1-3.6.1s-2.6 0-3.6-.1c-2.4-.1-3.6-1.3-3.7-3.7-.1-1-.1-1.2-.1-3.6s0-2.6.1-3.6C4.7 6 5.9 4.8 8.4 4.7c1-.1 1.2-.1 3.6-.1z'],
        'tiktok' => ['TikTok', 'M16.6 3c.4 2.2 1.8 3.6 4 3.8v3.1c-1.5.1-2.8-.4-4-1.2v6.1c0 3.3-2.3 5.7-5.6 5.7-3 0-5.4-2.4-5.4-5.4 0-3.3 2.8-5.8 6.3-5.3v3.2c-1.6-.4-3.1.6-3.1 2.1 0 1.3 1 2.3 2.3 2.3 1.4 0 2.4-1 2.4-2.7V3h3.1z'],
        'youtube' => ['YouTube', 'M21.6 7.2a2.5 2.5 0 0 0-1.8-1.8C18.2 5 12 5 12 5s-6.2 0-7.8.4A2.5 2.5 0 0 0 2.4 7.2 26 26 0 0 0 2 12a26 26 0 0 0 .4 4.8 2.5 2.5 0 0 0 1.8 1.8C5.8 19 12 19 12 19s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8A26 26 0 0 0 22 12a26 26 0 0 0-.4-4.8zM10 15V9l5.2 3L10 15z'],
        'linkedin' => ['LinkedIn', 'M6.9 21H3.3V9h3.6v12zM5.1 7.4a2.1 2.1 0 1 1 0-4.2 2.1 2.1 0 0 1 0 4.2zM21 21h-3.6v-5.8c0-1.4 0-3.2-2-3.2s-2.2 1.5-2.2 3.1V21H9.6V9H13v1.6h.1c.5-.9 1.7-1.9 3.4-1.9 3.6 0 4.3 2.4 4.3 5.5V21z'],
        'x' => ['X', 'M17.8 3h3.1l-6.8 7.7L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8L17.8 3zm-1.1 16.2h1.7L7.4 4.7H5.5l11.2 14.5z'],
    ];

    /** Platform setting key for a network, e.g. social_facebook. */
    public static function key(string $network): string
    {
        return 'social_'.$network;
    }

    /** @return list<array{network:string, label:string, path:string, url:string}> filled accounts in NETWORKS order */
    public static function all(): array
    {
        $links = [];
        foreach (self::NETWORKS as $network => [$label, $path]) {
            $url = trim((string) PlatformSetting::get(self::key($network)));
            if (str_starts_with($url, 'https://')) {
                $links[] = ['network' => $network, 'label' => $label, 'path' => $path, 'url' => $url];
            }
        }

        return $links;
    }
}
