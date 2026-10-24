<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest rating from the menu or the order page. At most 3 per hour per device/IP (hashed, never stored raw).
 * Happy guests (4-5 stars) are invited to rate on Google when the restaurant set its review link.
 */
class FeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(Entitlements::current()->allows('feedback'), 404);
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'order' => 'nullable|string|size:24',
        ]);

        $order = ! empty($data['order']) ? Order::query()->where('tracking_token', $data['order'])->with('table')->first() : null;
        $table = MenuController::currentTable($request);

        return self::record((int) $data['rating'], $data['comment'] ?? null, [
            'branch_id' => $order?->branch_id ?? $table?->branch_id ?? MenuCatalog::defaultBranch()->id,
            'order_id' => $order?->id, 'customer_id' => $order?->customer_id, 'table_name' => $order?->table?->name ?? $table?->name,
        ]);
    }

    /**
     * Saves one guest rating (also the guest pager's, App\Http\Controllers\Menu\PagerController) with the device
     * limit, and answers with the Google review link for a happy guest (4-5) when the restaurant set one.
     *
     * @param  array{branch_id:int, order_id:?int, customer_id:?int, table_name:?string}  $where
     */
    public static function record(int $rating, ?string $comment, array $where): JsonResponse
    {
        $ipHash = hash('sha256', client_ip().'|'.tenant()->getTenantKey().'|'.config('app.key'));
        // a rating tied to an order (pager, tracking) is capped per order, not per address: guests on the shop's
        // Wi-Fi share one address, so a per-address cap would drop the 4th guest's rating (complaints included)
        $recent = Feedback::query()->where('created_at', '>=', now()->subHour());
        $limited = ($where['order_id'] ?? null) !== null
            ? $recent->where('order_id', $where['order_id'])->count() >= 3
            : $recent->where('ip_hash', $ipHash)->whereNull('order_id')->count() >= 3;
        if ($limited) {
            return response()->json(['message' => __('Thank you! You already sent your rating.')], 429);
        }
        Feedback::query()->create($where + [
            'rating' => $rating, 'comment' => trim((string) $comment) ?: null, 'ip_hash' => $ipHash,
        ]);

        $google = Settings::get('google_review_url');

        return response()->json(['ok' => true, 'google' => $rating >= 4 && $google !== '' ? $google : null]);
    }
}
