<?php

namespace App\Models;

use App\Support\SiteServices;
use Illuminate\Database\Eloquent\Model;

/** A request for one of EgWeb's one-time services, sent from egmenu.app/services. Platform level (no restaurant). */
class ServiceRequest extends Model
{
    /** status => [arabic, english] */
    public const STATUSES = [
        'new' => ['جديد', 'New'],
        'contacted' => ['اتواصلنا', 'Contacted'],
        'done' => ['خلص', 'Done'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function serviceName(): string
    {
        return SiteServices::find($this->service)['name'] ?? $this->service;
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
