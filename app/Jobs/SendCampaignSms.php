<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Customers\Outreach;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the next SMS batch of a manual campaign and queues the rest after a pause, so the provider's per-minute limit
 * (config sms.per_minute) is kept. Tenant context comes from stancl.
 */
class SendCampaignSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $campaignId) {}

    public function handle(Outreach $outreach): void
    {
        $campaign = Campaign::query()->where('channel', Outreach::CHANNEL)->find($this->campaignId);
        if (! $campaign || (($campaign->options['sms'] ?? null) !== 'sending')) {
            return;
        }
        $perMinute = max(1, (int) config('sms.per_minute', 60));
        $batch = min(50, $perMinute);
        if ($outreach->sendSmsBatch($campaign, $batch)) {
            self::dispatch($campaign->id)->delay(now()->addSeconds((int) ceil(60 * $batch / $perMinute)));
        }
    }
}
