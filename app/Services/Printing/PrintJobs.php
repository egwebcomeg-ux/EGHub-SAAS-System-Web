<?php

namespace App\Services\Printing;

use App\Billing\PaymentMethods;
use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPrint;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Orders\Pager;
use App\Services\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Print jobs for the EgHub cashier app (window.EgHubNative.print(), ESC/POS on a Bluetooth / USB / LAN / Sunmi
 * printer): the same receipt, kitchen ticket and Z report the browser prints (dashboard.orders.receipt,
 * dashboard.pos.zreport-print), as the app's line list. The browser print is untouched: these are only asked for by
 * resources/js/native-print.js inside the app.
 *
 * Roles: "receipt" and one "kitchen:<station id>" per kitchen station of the order's lines ("kitchen:main" for lines
 * without a station, or every line when the plan has no stations); the app maps each role to a printer.
 *
 * The automatic printing claims each order + role once (order_prints, unique): two devices, a reload or a second
 * tab never print the same copy twice. A copy that failed to print is released so it can be tried again.
 */
final class PrintJobs
{
    public const RECEIPT = 'receipt';

    public const KITCHEN_MAIN = 'kitchen:main';

    /** Orders older than this are never printed by the automatic kitchen printing. */
    public const KITCHEN_WINDOW_HOURS = 3;

    public const MAX_COPIES = 5;

    /** A logo larger than this is left off the receipt (it travels as a data URL through the app's bridge). */
    public const MAX_LOGO_BYTES = 300_000;

    /** @return array{receipt_auto:bool, kitchen_auto:bool, copies:int, kitchen_copies:int, open_drawer:bool, logo:bool} */
    public static function settings(): array
    {
        $s = Settings::all();
        $copies = fn (string $key) => max(1, min(self::MAX_COPIES, (int) $s[$key]));

        return [
            'receipt_auto' => $s['pos_print_receipt_auto'] === '1',
            'kitchen_auto' => $s['pos_print_kitchen_auto'] === '1',
            'copies' => $copies('pos_print_copies'),
            'kitchen_copies' => $copies('pos_print_kitchen_copies'),
            'open_drawer' => $s['pos_print_open_drawer'] === '1',
            'logo' => $s['pos_print_logo'] === '1',
        ];
    }

    public static function isRole(string $role): bool
    {
        return $role === self::RECEIPT || $role === self::KITCHEN_MAIN || (bool) preg_match('/^kitchen:\d{1,10}$/', $role);
    }

    /**
     * The kitchen roles of the restaurant's printers panel: one per station (when the plan has stations) and main.
     *
     * @return array<string,string> role => label
     */
    public static function kitchenRoles(?Branch $branch = null): array
    {
        $roles = [];
        if (self::stationsOn()) {
            KitchenStation::query()->when($branch, fn ($q) => $q->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id)))
                ->orderBy('sort_order')->get()
                ->each(function (KitchenStation $s) use (&$roles) {
                    $roles['kitchen:'.$s->id] = tr($s->name, $s->name_en);
                });
        }

        return $roles + [self::KITCHEN_MAIN => $roles === [] ? __('Kitchen') : __('Kitchen (items without a station)')];
    }

    /**
     * The kitchen roles one order prints to: the stations of its lines.
     *
     * @return list<string>
     */
    public static function rolesFor(Order $order): array
    {
        $order->loadMissing('items');

        return self::linesByRole($order)->keys()->values()->all();
    }

    /**
     * The receipt: logo (optional), the place, the order number big, the lines with options and notes, the totals,
     * how it was paid, the guest pager's QR when it has one and the QR of the e-receipt.
     *
     * @return array<string,mixed>
     */
    public static function receipt(Order $order, ?int $copies = null): array
    {
        $order->loadMissing(['items', 'table', 'branch', 'terminal']);
        $settings = Settings::all();
        $options = self::settings();
        $currency = tr($settings['currency'], $settings['currency_en']);
        $lines = [];
        if ($options['logo'] && ($logo = self::logoDataUrl((string) $settings['logo']))) {
            $lines[] = ['t' => 'image', 'dataUrl' => $logo];
        }
        $lines[] = self::text(tr($settings['brand_name'], $settings['brand_name_en']) ?: (string) tenant()->name, 'center', true, 2);
        if ($order->branch) {
            $lines[] = self::text(trim(tr($order->branch->name, $order->branch->name_en).($order->branch->phone ? ' · '.$order->branch->phone : '')), 'center');
        }
        $lines[] = ['t' => 'divider'];
        array_push($lines, ...self::heading($order));
        $lines[] = ['t' => 'divider'];
        foreach ($order->items as $item) {
            $lines[] = ['t' => 'row', 'left' => $item->quantity.'× '.$item->name, 'right' => money($item->line_total)];
            array_push($lines, ...self::itemDetails($item));
        }
        if ($order->notes) {
            $lines[] = ['t' => 'divider'];
            $lines[] = self::text('⚠ '.$order->notes);
        }
        $lines[] = ['t' => 'divider'];
        if ($order->discount > 0 || $order->service > 0 || $order->tax > 0 || $order->delivery_fee > 0) {
            $lines[] = self::row(__('Subtotal'), money($order->subtotal));
        }
        if ($order->coupon_code && $order->coupon_discount > 0) {
            if ($order->discount - $order->coupon_discount > 0.004) {
                $lines[] = self::row(__('Discount'), '−'.money($order->discount - $order->coupon_discount));
            }
            $lines[] = self::row(__('Voucher :code', ['code' => $order->coupon_code]), '−'.money($order->coupon_discount));
        } elseif ($order->discount > 0) {
            $lines[] = self::row(__('Discount'), '−'.money($order->discount));
        }
        if ($order->service > 0) {
            $lines[] = self::row(__('Service'), money($order->service));
        }
        if ($order->tax > 0) {
            $lines[] = self::row(__('Tax'), money($order->tax));
        }
        if ($order->delivery_fee > 0) {
            $lines[] = self::row(__('Delivery fee'), money($order->delivery_fee));
        }
        $lines[] = self::row(__('Total'), money($order->total).' '.$currency, true);
        if ($order->corporate_amount > 0) {
            $lines[] = self::row(__('Corporate voucher'), '−'.money($order->corporate_amount));
        }
        $paid = $order->payment_status === 'paid';
        if ($paid && is_array($order->payments) && (count($order->payments) > 1 || ! empty($order->payments[0]['tendered']))) {
            foreach ($order->payments as $p) {
                $lines[] = self::row(PaymentMethods::label((string) $p['method']), money($p['amount']));
                if (! empty($p['tendered'])) {
                    $lines[] = self::row(__('Cash received').' '.money($p['tendered']), __('Change').' '.money($p['change'] ?? 0));
                }
            }
        }
        $lines[] = self::text($paid ? __('Paid').' · '.PaymentMethods::label($order->payment_method ?? 'cash') : __('Unpaid'), 'center');
        if (Pager::enabled() && Pager::forOrder($order) && ! Pager::isDone($order)) {
            $lines[] = ['t' => 'divider'];
            $lines[] = self::text(__('Scan so we can call you when your order is ready'), 'center', true);
            $lines[] = self::text(__('Your number').' #'.$order->number, 'center', true, 2);
            $lines[] = ['t' => 'qr', 'data' => $order->pagerUrl(), 'size' => 6];
        }
        $lines[] = ['t' => 'divider'];
        $lines[] = self::text(__('Thank you for your visit!'), 'center');
        $lines[] = ['t' => 'qr', 'data' => $order->receiptUrl(), 'size' => 5];
        $lines[] = self::text(__('Scan to keep this receipt on your phone'), 'center');
        $lines[] = self::text(tenant()->url(), 'center');
        $lines[] = ['t' => 'feed', 'n' => 3];

        return [
            'role' => self::RECEIPT, 'jobId' => self::jobId($order, self::RECEIPT),
            'copies' => $copies ?? $options['copies'], 'openDrawer' => $options['open_drawer'] && $paid && self::paidCash($order),
            'cut' => true, 'lines' => $lines,
        ];
    }

    /**
     * One kitchen ticket: only the lines of $role's station, no prices, the number big. Null when the order has no
     * line for that station.
     *
     * @return array<string,mixed>|null
     */
    public static function kitchen(Order $order, string $role, ?int $copies = null): ?array
    {
        $order->loadMissing(['items', 'table', 'branch', 'terminal']);
        $items = self::linesByRole($order)->get($role);
        if (! $items || $items->isEmpty()) {
            return null;
        }
        $station = $role === self::KITCHEN_MAIN ? null : KitchenStation::query()->find((int) substr($role, 8));
        $lines = [self::text(__('Kitchen ticket').($station ? ' — '.tr($station->name, $station->name_en) : ''), 'center', true, 2)];
        array_push($lines, ...self::heading($order, kitchen: true));
        $lines[] = ['t' => 'divider'];
        foreach ($items as $item) {
            $lines[] = self::text($item->quantity.'× '.$item->name, 'right', true, 2);
            array_push($lines, ...self::itemDetails($item));
        }
        if ($order->notes) {
            $lines[] = ['t' => 'divider'];
            $lines[] = self::text('⚠ '.$order->notes, 'right', true);
        }
        $lines[] = ['t' => 'feed', 'n' => 3];

        return [
            'role' => $role, 'jobId' => self::jobId($order, $role), 'copies' => $copies ?? self::settings()['kitchen_copies'],
            'openDrawer' => false, 'cut' => true, 'lines' => $lines,
        ];
    }

    /**
     * The end-of-day (Z) report as the 80mm print shows it (App\Services\Reports\ZReport::build()).
     *
     * @param  array<string,mixed>  $z
     * @return array<string,mixed>
     */
    public static function zReport(array $z, Branch $branch, CarbonInterface $day, ?string $terminal = null): array
    {
        $settings = Settings::all();
        $lines = [
            self::text(tr($settings['brand_name'], $settings['brand_name_en']) ?: (string) tenant()->name, 'center', true, 2),
            self::text(__('End of day (Z)'), 'center', true),
            self::text(tr($branch->name, $branch->name_en).($terminal ? ' · '.$terminal : ''), 'center'),
            self::text($day->format('Y-m-d'), 'center'),
            ['t' => 'divider'],
            self::row(__('Paid orders'), (string) $z['orders']),
            self::row(__('Sales'), money($z['gross'])),
            self::row(__('Discounts'), money($z['discounts'])),
            self::row(__('Service'), money($z['service'])),
            self::row(__('Tax'), money($z['tax'])),
            self::row(__('Refunds'), money($z['refunds']['total'])),
            self::row(__('Net sales'), money($z['net']), true),
            ['t' => 'divider'],
        ];
        foreach ($z['net_by_method'] as $method => $amount) {
            $lines[] = self::row(PaymentMethods::label((string) $method), money($amount));
        }
        array_push($lines,
            ['t' => 'divider'],
            self::row(__('Cancelled orders'), $z['cancelled']['count'].' · '.money($z['cancelled']['total'])),
            self::row(__('Not paid yet'), money($z['unpaid']['total'])),
            self::row(__('Cash in'), money($z['cash']['in'])),
            self::row(__('Cash out'), money($z['cash']['out'])),
        );
        foreach ($z['shifts'] as $shift) {
            $lines[] = ['t' => 'divider'];
            $lines[] = self::text(trim($shift['user'].' '.($shift['terminal'] ?? '')), 'right', true);
            $lines[] = self::row(__('Expected cash'), money($shift['expected']));
            if (($shift['counted'] ?? null) !== null) {
                $lines[] = self::row(__('Counted'), money($shift['counted']));
                $lines[] = self::row(__('Difference'), money($shift['difference'] ?? 0));
            }
        }
        $lines[] = ['t' => 'divider'];
        $lines[] = self::text(__('Printed :time', ['time' => local_time(now())]), 'center');
        $lines[] = ['t' => 'feed', 'n' => 3];

        return ['role' => self::RECEIPT, 'jobId' => 'z-'.$branch->id.'-'.$day->format('Ymd').'-'.now()->timestamp, 'copies' => 1,
            'openDrawer' => false, 'cut' => true, 'lines' => $lines];
    }

    /** Claims an order's copy for the automatic printing: true once, false when it was already printed. */
    public static function claim(Order $order, string $role, ?User $by = null): bool
    {
        try {
            OrderPrint::query()->create(['order_id' => $order->id, 'role' => $role, 'user_id' => $by?->id]);

            return true;
        } catch (QueryException) {
            return false; // the unique (order, role): another device or tab printed it already
        }
    }

    /** A claimed copy failed to print: it may be tried again. */
    public static function release(Order $order, string $role): void
    {
        OrderPrint::query()->where('order_id', $order->id)->where('role', $role)->delete();
    }

    /**
     * Orders of $branch still waiting for a kitchen ticket by the automatic printing: new or preparing, placed after
     * the setting was switched on (and in the last few hours), with the kitchen roles not printed yet.
     *
     * @return list<array{id:int, number:int, roles:list<string>}>
     */
    public static function pendingKitchen(Branch $branch, int $limit = 20): array
    {
        if (! self::settings()['kitchen_auto']) {
            return [];
        }
        $since = now()->subHours(self::KITCHEN_WINDOW_HOURS);
        $on = Settings::get('pos_print_kitchen_since');
        if ($on !== '' && ($onAt = rescue(fn () => Carbon::parse($on)->utc(), null, false)) && $onAt->gt($since)) {
            $since = $onAt;
        }
        $orders = Order::query()->where('branch_id', $branch->id)->whereIn('status', ['new', 'preparing'])
            ->where('created_at', '>=', $since)->with('items')->oldest('id')->limit(100)->get();
        $printed = OrderPrint::query()->whereIn('order_id', $orders->pluck('id'))->where('role', 'like', 'kitchen:%')->get()
            ->groupBy('order_id')->map(fn (Collection $rows) => $rows->pluck('role')->all());
        $out = [];
        foreach ($orders as $order) {
            $roles = array_values(array_diff(self::rolesFor($order), $printed[$order->id] ?? []));
            if ($roles !== []) {
                $out[] = ['id' => $order->id, 'number' => $order->number, 'roles' => $roles];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return Collection<string, Collection<int, OrderItem>> role => the order's lines for it */
    private static function linesByRole(Order $order): Collection
    {
        $stations = self::stationsOn();

        return $order->items->groupBy(fn (OrderItem $i) => $stations && $i->kitchen_station_id ? 'kitchen:'.$i->kitchen_station_id : self::KITCHEN_MAIN);
    }

    private static function stationsOn(): bool
    {
        return Entitlements::current()->allows('kitchen_stations');
    }

    /** @return list<array<string,mixed>> the order number big, where it goes, when, who */
    private static function heading(Order $order, bool $kitchen = false): array
    {
        $type = Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? $order->type;
        $lines = [self::text('#'.$order->number, 'center', true, 3)];
        if ($order->offline_ref) {
            $lines[] = self::text('('.__('was :ref', ['ref' => $order->offline_ref]).')', 'center');
        }
        $lines[] = self::text($type.($order->table ? ' · '.__('Table :name', ['name' => $order->table->name]) : ''), 'center', $kitchen, $kitchen ? 2 : 1);
        $lines[] = self::text(local_time($order->created_at).($order->terminal ? ' · '.$order->terminal->label() : ''), 'center');
        if ($order->customer_name || ($order->customer_phone && ! $kitchen)) {
            $lines[] = self::text(trim($order->customer_name.' '.($kitchen ? '' : $order->customer_phone)), 'center');
        }
        if ($order->delivery_address && ! $kitchen) {
            $lines[] = self::text((string) ($order->delivery_address['zone'] ?? ''), 'center', true);
            $lines[] = self::text((string) ($order->delivery_address['text'] ?? ''), 'center');
        }

        return array_values(array_filter($lines, fn (array $l) => ($l['text'] ?? '') !== ''));
    }

    /** @return list<array<string,mixed>> */
    private static function itemDetails(OrderItem $item): array
    {
        $lines = [];
        if ($item->options) {
            $lines[] = self::text('  + '.collect($item->options)->pluck('name')->implode('، '));
        }
        if ($item->notes) {
            $lines[] = self::text('  ⚠ '.$item->notes, 'right', true);
        }

        return $lines;
    }

    /** @return array{t:string, text:string, align:string, bold:bool, size:int} */
    private static function text(string $text, string $align = 'right', bool $bold = false, int $size = 1): array
    {
        return ['t' => 'text', 'text' => $text, 'align' => $align, 'bold' => $bold, 'size' => $size];
    }

    /** @return array{t:string, left:string, right:string, bold:bool} */
    private static function row(string $left, string $right, bool $bold = false): array
    {
        return ['t' => 'row', 'left' => $left, 'right' => $right, 'bold' => $bold];
    }

    private static function jobId(Order $order, string $role): string
    {
        return $order->id.':'.$role;
    }

    private static function paidCash(Order $order): bool
    {
        if (is_array($order->payments) && $order->payments !== []) {
            return collect($order->payments)->contains(fn ($p) => ($p['method'] ?? null) === 'cash');
        }

        return ($order->payment_method ?? null) === 'cash';
    }

    private static function logoDataUrl(string $logo): ?string
    {
        if ($logo === '' || ! preg_match('/\.(png|jpe?g|webp)$/i', $logo)) {
            return null;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($logo) || $disk->size($logo) > self::MAX_LOGO_BYTES) {
            return null;
        }
        $mime = match (strtolower(pathinfo($logo, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($logo));
    }
}
