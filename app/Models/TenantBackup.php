<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup zip of the restaurant (App\Services\Backups\TenantBackups). The file lives on the private disk under
 * backups/{tenant}/{filename}; the row is what the dashboard lists and what the prune step works from.
 */
class TenantBackup extends TenantModel
{
    public const KIND_MANUAL = 'manual';

    public const KIND_SCHEDULED = 'scheduled';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** kind => [arabic, english] */
    public const KINDS = [
        self::KIND_MANUAL => ['يدوية', 'Manual'],
        self::KIND_SCHEDULED => ['أسبوعية', 'Weekly'],
    ];

    /** status => [arabic, english] */
    public const STATUSES = [
        self::STATUS_BUILDING => ['بتتجهّز', 'Building'],
        self::STATUS_READY => ['جاهزة', 'Ready'],
        self::STATUS_FAILED => ['فشلت', 'Failed'],
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'summary' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Path of the zip inside the private disk: backups/{tenant}/{filename}. */
    public function storagePath(): string
    {
        return 'backups/'.$this->tenant_id.'/'.$this->filename;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function kindLabel(): string
    {
        return self::pick(self::KINDS[$this->kind] ?? [$this->kind, $this->kind]);
    }

    public function statusLabel(): string
    {
        return self::pick(self::STATUSES[$this->status] ?? [$this->status, $this->status]);
    }

    /** "1.2 MB" style size for the list. */
    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format(max(1, (int) ceil($bytes / 1024))).' KB';
    }

    /** @param array{0: string, 1: string} $pair */
    private static function pick(array $pair): string
    {
        return app()->getLocale() === 'en' ? $pair[1] : $pair[0];
    }
}
