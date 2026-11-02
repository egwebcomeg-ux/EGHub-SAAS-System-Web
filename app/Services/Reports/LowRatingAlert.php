<?php

namespace App\Services\Reports;

use App\Models\Feedback;
use App\Models\User;
use App\Notifications\LowRatingReceived;
use App\Services\Push\StaffPush;
use App\Services\Settings;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the current restaurant's owner and managers of a rating of MAX_STARS or less, by email and on their phones, so
 * they can answer before it becomes a public review. A manager tied to a branch hears only about that branch. The bell
 * in the dashboard shows the same ratings. An alert never breaks saving the guest's rating.
 */
final class LowRatingAlert
{
    public const MAX_STARS = 2;

    public function received(Feedback $feedback): void
    {
        if ($feedback->rating > self::MAX_STARS) {
            return;
        }
        try {
            $feedback->loadMissing('order');
            $where = implode(' · ', array_filter([
                $feedback->order ? __('Order #:n', ['n' => $feedback->order->number]) : null,
                $feedback->table_name ? __('Table :name', ['name' => $feedback->table_name]) : null,
            ]));
            $managers = User::query()->where('is_active', true)->role(['owner', 'manager'])->whereNotNull('email')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $feedback->branch_id))->get();
            if ($managers->isNotEmpty()) {
                $restaurant = tr(Settings::get('brand_name'), Settings::get('brand_name_en')) ?: (string) tenant()->name;
                Notification::send($managers, new LowRatingReceived($restaurant, (int) $feedback->rating, $where, $feedback->comment, platform_url('app', '/feedback')));
            }
            app(StaffPush::class)->lowRating($feedback, $where);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
