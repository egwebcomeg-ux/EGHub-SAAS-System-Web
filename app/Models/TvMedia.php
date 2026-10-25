<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/**
 * A picture or a short video the restaurant uploaded for its TV screens (its own library, reused by any slide).
 * Pictures are re-encoded to WebP by ImageService (profile "screen"); videos are MP4 / WebM checked by their magic
 * bytes (App\Services\ItemVideo), stored under tenants/{id}/screens/ on the public disk.
 */
class TvMedia extends TenantModel
{
    public const KINDS = ['image', 'video'];

    /** Biggest video a restaurant may upload, in KB (80 MB). */
    public const MAX_VIDEO_KB = 81920;

    protected $table = 'tv_media';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    /** Same-origin path: the player on eghub.app and the old link on any host both read /storage/... */
    public function url(): string
    {
        return '/storage/'.$this->path;
    }
}
