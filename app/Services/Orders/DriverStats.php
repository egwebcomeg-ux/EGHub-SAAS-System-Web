<?php

namespace App\Services\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What each delivery driver did in a period: orders delivered, deliveries that failed (from the audit trail, since
 * a failed order is given to another driver and loses its mark), the average minutes from pick-up to the door, the
 * cash they took at the door and what guests paid them by card / InstaPay / wallet. Aggregated in PHP like the
 * sales reports (the same code on MySQL and SQLite). Staff tied to a branch see only that branch's orders.
 */
final class DriverStats
{
    public function __construct(
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly ?int $branchId = null,
    ) {}

    /**
     * One row per driver (every driver given, active or not, so a manager sees zeros as well).
     *
     * @param  Collection<int,User>  $drivers
     * @return list<array{id:int, name:string, delivered:int, failed:int, avg_minutes:?int, cash:float, other:float}>
     */
    public function perDriver(Collection $drivers): array
    {
        $ids = $drivers->modelKeys();
        if ($ids === []) {
            return [];
        }
        $delivered = Order::query()->whereIn('driver_id', $ids)->where('delivery_status', 'delivered')
            ->whereBetween('delivered_at', [$this->from, $this->to])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->get(['driver_id', 'branch_id', 'picked_up_at', 'delivered_at', 'cash_collected', 'total', 'delivery_payment_method'])
            ->groupBy('driver_id');
        $failed = $this->failed($ids);

        return $drivers->map(function (User $driver) use ($delivered, $failed) {
            $orders = $delivered->get($driver->id) ?? collect();
            $minutes = $orders->filter(fn (Order $o) => $o->picked_up_at && $o->delivered_at)
                ->map(fn (Order $o) => $o->picked_up_at->diffInMinutes($o->delivered_at));

            return [
                'id' => $driver->id, 'name' => $driver->name,
                'delivered' => $orders->count(),
                'failed' => (int) ($failed[$driver->id] ?? 0),
                'avg_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
                'cash' => round((float) $orders->sum('cash_collected'), 2),
                'other' => round((float) $orders->filter(fn (Order $o) => ! in_array($o->delivery_payment_method, [null, 'cash'], true))->sum('total'), 2),
            ];
        })->values()->all();
    }

    /** Only the drivers who delivered or failed something in the period, busiest first. */
    public function active(Collection $drivers): array
    {
        $rows = array_filter($this->perDriver($drivers), fn (array $r) => $r['delivered'] > 0 || $r['failed'] > 0);
        usort($rows, fn (array $a, array $b) => [$b['delivered'], $b['failed']] <=> [$a['delivered'], $a['failed']]);

        return array_values($rows);
    }

    /**
     * Failed deliveries per driver in the period, from the audit trail (DeliveryService::fail records one entry).
     *
     * @param  list<int>  $driverIds
     * @return array<int,int>
     */
    private function failed(array $driverIds): array
    {
        $logs = AuditLog::query()->where('action', 'delivery.failed')->whereBetween('created_at', [$this->from, $this->to])->get(['meta']);
        if ($this->branchId) {
            $orderIds = $logs->map(fn (AuditLog $l) => (int) ($l->meta['id'] ?? 0))->filter()->unique()->values();
            $ofBranch = Order::query()->whereIn('id', $orderIds)->where('branch_id', $this->branchId)->pluck('id')->flip();
            $logs = $logs->filter(fn (AuditLog $l) => $ofBranch->has((int) ($l->meta['id'] ?? 0)));
        }

        return $logs->map(fn (AuditLog $l) => (int) ($l->meta['driver'] ?? 0))->filter(fn (int $id) => in_array($id, $driverIds, true))
            ->countBy()->all();
    }
}
