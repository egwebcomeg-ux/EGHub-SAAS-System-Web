<?php

namespace App\Notifications;

use App\Models\PlatformAdmin;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * To whoever runs the platform: a scheduled command failed. Sent right away, not queued: the queue itself runs from the
 * scheduler, so a queued warning could wait on the very thing that broke.
 */
class ScheduledTaskFailed extends Notification
{
    private const QUIET_HOURS = 6;

    public function __construct(public string $task) {}

    public static function alert(string $task): void
    {
        if (Cache::add('scheduled-task-failed:'.sha1($task), 1, now()->addHours(self::QUIET_HOURS))) {
            Notifications::send(PlatformAdmin::notifiable('settings.manage'), new self($task));
        }
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = self::shortName($this->task);

        return (new MailMessage)
            ->subject(__('Scheduled task failed: :task', ['task' => $name]))
            ->line(__('This scheduled task failed on the server: :task', ['task' => $name]))
            ->action(__('Open system health'), platform_url('admin', '/system'))
            ->salutation('EgHub');
    }

    /** "'/usr/bin/php' 'artisan' eghub:billing" reads as eghub:billing. */
    private static function shortName(string $task): string
    {
        return trim((string) preg_replace("/^.*'?artisan'?\s+/", '', $task));
    }
}
