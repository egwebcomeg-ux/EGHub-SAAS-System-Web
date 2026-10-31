<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one staff notification to every registered phone of the given users (App\Services\Push\StaffPush). A token
 * FCM calls dead is deleted; any other failure is logged and dropped (a late "new order" alert is worth nothing).
 * Tenant context is restored by stancl's QueueTenancyBootstrapper, so only this restaurant's tokens are read.
 * $dataOnly sends no "notification" block: the app draws the message itself (its own channel and sound).
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  list<int>  $userIds
     * @param  array<string,string>  $data
     */
    public function __construct(public array $userIds, public string $title, public string $body, public array $data = [], public bool $dataOnly = false) {}

    public function handle(FcmClient $fcm): void
    {
        $tokens = DeviceToken::query()->whereIn('user_id', $this->userIds)->get();
        foreach ($tokens as $token) {
            if ($fcm->send($token->token, $this->title, $this->body, $this->data, $this->dataOnly) === FcmClient::INVALID) {
                $token->delete();
            }
        }
    }
}
