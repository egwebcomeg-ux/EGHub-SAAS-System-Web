<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Marketing\Campaigns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends the next 50 messages of a campaign and queues itself for the rest. Tenant context comes from stancl. */
class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $campaignId) {}

    public function handle(Campaigns $campaigns): void
    {
        $campaign = Campaign::query()->with('coupon')->find($this->campaignId);
        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }
        if ($campaigns->sendBatch($campaign)) {
            self::dispatch($campaign->id);
        }
    }
}
