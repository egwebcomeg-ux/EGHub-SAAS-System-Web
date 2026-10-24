<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\KbArticle;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use App\Support\BusinessType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Sample rows EgWeb fills in for a prospect's trial, so every module shows a real-looking picture instead of an
 * empty page: orders of the last 7 days (board, reports, best sellers), guests, a loyalty card, a coupon, an offer,
 * tables, a booking, an FAQ answer, a driver and a corporate contract — each only when the plan has the module.
 *
 * Every row it makes is listed by id in the tenant setting demo_content ({kind: [ids]}), and clear() removes exactly
 * those rows, nothing else. Names carry "(تجربة)" and phones are 0100000000x, so nobody mistakes them for real
 * guests. It never seeds a restaurant that already has orders, and always runs inside the given restaurant only.
 */
final class DemoContent
{
    /** The label on every sample name. */
    public const MARK = '(تجربة)';

    public const ORDERS = 12;

    /** Delete order: children before the rows they point at. */
    private const KINDS = [
        'loyalty_cards' => LoyaltyCard::class,
        'orders' => Order::class,
        'reservations' => Reservation::class,
        'corporate_contracts' => CorporateContract::class,
        'customers' => Customer::class,
        'loyalty_programs' => LoyaltyProgram::class,
        'coupons' => Coupon::class,
        'offers' => Offer::class,
        'kb_articles' => KbArticle::class,
        'tables' => DiningTable::class,
        'users' => User::class,
        'menu_items' => MenuItem::class,
    ];

    public function __construct(private CorporateVouchers $corporate) {}

    /**
     * Seeds $tenant. Returns how many rows of each kind were made, or null when it was refused (the restaurant has
     * orders already, or holds sample rows still).
     *
     * @return array<string,int>|null
     */
    public function seed(Tenant $tenant): ?array
    {
        return $this->inTenant($tenant, function () {
            if (Order::query()->exists() || self::present()) {
                return null;
            }

            return DB::transaction(function () {
                $ids = [];
                $rights = Entitlements::current();
                $branch = Branch::query()->orderBy('id')->firstOrFail();
                $owner = User::query()->role('owner')->orderBy('id')->first();

                $items = $this->menuItems($ids);
                $customers = $this->customers($ids);
                $this->orders($ids, $branch, $items, $customers);

                if ($rights->allows('loyalty_stamps|loyalty_points')) {
                    $this->loyalty($ids, $customers);
                }
                if ($rights->allows('campaigns')) {
                    $ids['coupons'][] = Coupon::query()->create([
                        'code' => 'TRY10', 'type' => 'percent', 'value' => 10, 'min_order' => 0, 'once_per_customer' => true,
                        'is_active' => true, 'source' => 'voucher', 'note' => 'كوبون خصم 10% '.self::MARK,
                        'expires_at' => now()->addDays(30),
                    ])->id;
                }
                if ($rights->allows('offers')) {
                    // a week of 10% off the last category the sample orders use (desserts on the starter menus)
                    $category = Category::query()->find($items->last()?->category_id);
                    $ids['offers'][] = Offer::query()->create([
                        'title' => trim('خصم 10% على '.($category->name ?? 'المنيو').' '.self::MARK),
                        'title_en' => '10% off '.(($category?->name_en ?: null) ?? 'the menu').' (demo)', 'discount_percent' => 10,
                        'category_id' => $category?->id, 'is_active' => true,
                        'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(7)->toDateString(),
                    ])->id;
                }
                $this->tables($ids, $branch);
                if ($rights->allows('reservations')) {
                    $at = now()->addDay()->setTime(20, 0);
                    $ids['reservations'][] = Reservation::query()->create([
                        'branch_id' => $branch->id, 'customer_id' => $customers[0]->id ?? null, 'name' => $customers[0]->name ?? 'ضيف '.self::MARK,
                        'phone' => $customers[0]->phone ?? '01000000001', 'party_size' => 4, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(90),
                        'status' => 'confirmed', 'confirmed_at' => now(), 'source' => 'staff', 'token' => random_token(12),
                        'notes' => 'حجز عيد ميلاد '.self::MARK,
                    ])->id;
                }
                if ($rights->allows('knowledge_base')) {
                    $ids['kb_articles'][] = KbArticle::query()->create([
                        'question' => 'مواعيد الشغل إمتى؟ '.self::MARK, 'question_en' => 'What are your opening hours? (demo)',
                        'answer' => 'مفتوحين كل يوم من 9 الصبح لحد 12 بالليل.', 'answer_en' => 'Open every day from 9 am to midnight.',
                        'visibility' => 'public', 'is_active' => true,
                    ])->id;
                }
                if ($rights->allows('delivery_zones|online_ordering') && $rights->canAdd('staff')
                    && Role::query()->where('name', 'driver')->where('guard_name', 'web')->exists()) {
                    $driver = User::query()->create([
                        'name' => 'كابتن محمود '.self::MARK, 'email' => 'driver.'.tenant()->getTenantKey().'@demo.egmenu.app',
                        'password' => Str::password(32), 'is_active' => false, 'branch_id' => $branch->id,
                    ]);
                    $driver->assignRole('driver');
                    $ids['users'][] = $driver->id;
                }
                if ($rights->allows('corporate_vouchers') && $owner) {
                    $contract = $this->corporate->createContract([
                        'name' => 'شركة النيل للبرمجيات '.self::MARK, 'contact_name' => 'أ. سارة', 'contact_phone' => '01000000005',
                        'prefix' => 'DEMO', 'voucher_value' => 150, 'quantity' => 5, 'expires_on' => now()->addMonths(3)->toDateString(),
                        'mode' => 'single', 'paid_amount' => 750,
                    ], $owner);
                    $ids['corporate_contracts'][] = $contract->id;
                }

                $ids = array_map(fn (array $list) => array_values(array_unique(array_map('intval', $list))), array_filter($ids));
                Settings::put(['demo_content' => json_encode($ids)]);

                return array_map('count', $ids);
            });
        });
    }

    /** Whether the current restaurant still holds sample rows (the dashboard banner). */
    public static function present(): bool
    {
        return self::ids() !== [];
    }

    /** @return array<string,list<int>> the sample rows of the current restaurant */
    public static function ids(): array
    {
        $ids = json_decode(Settings::get('demo_content'), true);

        return is_array($ids) ? array_filter(array_intersect_key($ids, self::KINDS), fn ($list) => is_array($list) && $list !== []) : [];
    }

    /**
     * Removes the sample rows of the current restaurant, exactly the ids listed, and forgets the list. A sample
     * order the owner touched since still goes (it was never a real sale); a real order is never listed.
     *
     * @return array<string,int> rows removed per kind
     */
    public function clear(): array
    {
        $ids = self::ids();
        $removed = DB::transaction(function () use ($ids) {
            $removed = [];
            foreach (self::KINDS as $kind => $model) {
                $list = $ids[$kind] ?? [];
                if ($list === []) {
                    continue;
                }
                if ($kind === 'orders') {
                    // items and status history cascade; the guests' visit counts go with the guests themselves
                    OrderItem::query()->whereIn('order_id', $list)->delete();
                }
                if ($kind === 'corporate_contracts') {
                    CorporateVoucher::query()->whereIn('corporate_contract_id', $list)->delete();
                }
                if ($kind === 'users') {
                    // the sample driver: unassigned from any order first, then the account and its roles
                    Order::query()->whereIn('driver_id', $list)->update(['driver_id' => null]);
                    foreach (User::query()->whereIn('id', $list)->get() as $user) {
                        $user->syncRoles([]);
                        $user->delete();
                    }
                    $removed[$kind] = count($list);

                    continue;
                }
                // the tenant scope of every model keeps this inside the current restaurant
                $removed[$kind] = $model::query()->whereIn('id', $list)->delete();
            }
            Settings::put(['demo_content' => '']);

            return $removed;
        });
        Entitlements::flush();

        return $removed;
    }

    /**
     * The menu the sample orders are made of: the restaurant's own items when it has some, else a few sample items
     * in its starter categories (listed too, so they go with the rest).
     *
     * @param  array<string,list<int>>  $ids
     * @return Collection<int,MenuItem>
     */
    private function menuItems(array &$ids)
    {
        $existing = MenuItem::query()->where('is_active', true)->orderBy('id')->limit(8)->get();
        if ($existing->count() >= 3) {
            return $existing;
        }
        $categories = Category::query()->orderBy('sort_order')->get();
        if ($categories->isEmpty()) {
            $categories = collect([Category::query()->create(['name' => 'أصناف '.self::MARK, 'name_en' => 'Items (demo)', 'sort_order' => 1])]);
        }
        $food = in_array(BusinessType::current(), ['restaurant', 'both'], true);
        $samples = $food
            ? [['شوربة عدس', 'Lentil soup', 45], ['سلطة سيزر', 'Caesar salad', 85], ['فراخ مشوية', 'Grilled chicken', 180], ['كفتة', 'Kofta', 160], ['ليمون نعناع', 'Lemon mint', 45], ['أم علي', 'Om Ali', 70]]
            : [['إسبريسو', 'Espresso', 45], ['لاتيه', 'Latte', 65], ['آيس كوفي', 'Iced coffee', 75], ['شاي بالنعناع', 'Mint tea', 30], ['تشيز كيك', 'Cheesecake', 90], ['كرواسون', 'Croissant', 55]];
        $made = collect();
        foreach ($samples as $i => [$name, $en, $price]) {
            $category = $categories[(int) floor($i * $categories->count() / count($samples))] ?? $categories->first();
            $item = MenuItem::query()->create([
                'category_id' => $category->id, 'name' => $name.' '.self::MARK, 'name_en' => $en.' (demo)', 'price' => $price, 'sort_order' => $i + 1,
            ]);
            $ids['menu_items'][] = $item->id;
            $made->push($item);
        }

        return $existing->concat($made);
    }

    /**
     * @param  array<string,list<int>>  $ids
     * @return list<Customer>
     */
    private function customers(array &$ids): array
    {
        $names = ['أحمد علي', 'منى حسن', 'كريم سمير', 'ياسمين عادل', 'عمر خالد'];
        $out = [];
        foreach ($names as $i => $name) {
            $customer = Customer::query()->create([
                'name' => $name.' '.self::MARK, 'phone' => '0100000000'.($i + 1), 'source' => 'pos', 'marketing_opt_in' => $i % 2 === 0,
            ]);
            $ids['customers'][] = $customer->id;
            $out[] = $customer;
        }

        return $out;
    }

    /**
     * Twelve orders over the last 7 days: most served and paid, today's still on the board, one cancelled.
     *
     * @param  array<string,list<int>>  $ids
     * @param  Collection<int,MenuItem>  $items
     * @param  list<Customer>  $customers
     */
    private function orders(array &$ids, Branch $branch, $items, array $customers): void
    {
        $tables = DiningTable::query()->where('branch_id', $branch->id)->orderBy('sort_order')->get();
        // [days ago, hour, status, type, source]
        $plan = [
            [6, 13, 'served', 'dine_in', 'qr'], [6, 20, 'served', 'takeaway', 'pos'], [5, 14, 'served', 'dine_in', 'qr'],
            [4, 19, 'served', 'delivery', 'online'], [4, 21, 'cancelled', 'takeaway', 'online'], [3, 12, 'served', 'dine_in', 'pos'],
            [2, 18, 'served', 'dine_in', 'qr'], [2, 22, 'served', 'delivery', 'online'], [1, 15, 'served', 'takeaway', 'pos'],
            [0, 0, 'ready', 'dine_in', 'qr'], [0, 0, 'preparing', 'takeaway', 'online'], [0, 0, 'new', 'dine_in', 'qr'],
        ];
        $number = (int) Order::query()->max('number');
        foreach ($plan as $i => [$daysAgo, $hour, $status, $type, $source]) {
            $at = $daysAgo === 0 ? now()->subMinutes(45 - $i * 3) : now()->subDays($daysAgo)->setTime($hour, ($i * 7) % 60);
            $customer = $customers[$i % count($customers)] ?? null;
            $lines = [];
            foreach ([$i % $items->count(), ($i * 3 + 1) % $items->count()] as $n => $index) {
                $item = $items[$index];
                $qty = $n === 0 ? 1 + ($i % 2) : 1;
                $lines[$item->id] = ['item' => $item, 'qty' => ($lines[$item->id]['qty'] ?? 0) + $qty];
            }
            $subtotal = array_sum(array_map(fn ($l) => (float) $l['item']->price * $l['qty'], $lines));
            $order = new Order([
                'branch_id' => $branch->id, 'number' => ++$number, 'type' => $type, 'source' => $source, 'status' => $status,
                'dining_table_id' => $type === 'dine_in' ? $tables[$i % max(1, $tables->count())]->id ?? null : null,
                'customer_id' => $type === 'dine_in' && $i % 3 === 0 ? null : $customer?->id,
                'customer_name' => $type === 'dine_in' && $i % 3 === 0 ? '' : (string) $customer?->name,
                'customer_phone' => $type === 'dine_in' && $i % 3 === 0 ? '' : (string) $customer?->phone,
                'delivery_address' => $type === 'delivery' ? 'شارع التسعين، التجمع الخامس '.self::MARK : null,
                'subtotal' => $subtotal, 'total' => $subtotal,
                'payment_status' => $status === 'served' ? 'paid' : 'unpaid', 'payment_method' => $status === 'served' ? ($i % 3 === 0 ? 'card' : 'cash') : null,
                'tracking_token' => random_token(16),
                'preparing_at' => in_array($status, ['preparing', 'ready', 'served'], true) ? $at->copy()->addMinutes(2) : null,
                'ready_at' => in_array($status, ['ready', 'served'], true) ? $at->copy()->addMinutes(14) : null,
                'completed_at' => $status === 'served' ? $at->copy()->addMinutes(30) : null,
                'cancelled_at' => $status === 'cancelled' ? $at->copy()->addMinutes(5) : null,
                'cancel_reason' => $status === 'cancelled' ? 'الزبون لغى الطلب '.self::MARK : null,
            ]);
            $order->created_at = $at;
            $order->updated_at = $at;
            $order->save();
            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id, 'menu_item_id' => $line['item']->id, 'name' => $line['item']->name,
                    'unit_price' => $line['item']->price, 'quantity' => $line['qty'], 'line_total' => (float) $line['item']->price * $line['qty'],
                ]);
            }
            if ($status === 'served' && $customer && $order->customer_id) {
                $customer->forceFill([
                    'visits_count' => $customer->visits_count + 1, 'total_spent' => $customer->total_spent + $subtotal, 'last_visit_at' => $at,
                ])->save();
            }
            $ids['orders'][] = $order->id;
        }
    }

    /**
     * The restaurant's loyalty card (a sample one when it has none yet) with two guests part-way to their reward.
     *
     * @param  array<string,list<int>>  $ids
     * @param  list<Customer>  $customers
     */
    private function loyalty(array &$ids, array $customers): void
    {
        $program = LoyaltyProgram::query()->where('is_active', true)->first();
        if (! $program) {
            $program = LoyaltyProgram::query()->create([
                'type' => 'visits', 'title' => 'كارت الولاء '.self::MARK, 'title_en' => 'Loyalty card (demo)', 'stamps_required' => 8,
                'reward_label' => 'مشروب مجاني', 'reward_label_en' => 'A free drink',
                'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup'],
            ]);
            $ids['loyalty_programs'][] = $program->id;
        }
        foreach (array_slice($customers, 0, 2) as $i => $customer) {
            $ids['loyalty_cards'][] = LoyaltyCard::query()->create([
                'loyalty_program_id' => $program->id, 'customer_id' => $customer->id, 'stamps' => 5 - $i * 2,
                'lifetime_stamps' => 5 - $i * 2, 'last_stamp_at' => now()->subDays(1 + $i),
            ])->id;
        }
    }

    /** @param  array<string,list<int>>  $ids */
    private function tables(array &$ids, Branch $branch): void
    {
        if (! Entitlements::current()->canAdd('tables', 2)) {
            return;
        }
        $last = (int) DiningTable::query()->where('branch_id', $branch->id)->max('sort_order');
        foreach (['تراس 1', 'تراس 2'] as $i => $name) {
            $ids['tables'][] = DiningTable::query()->create([
                'branch_id' => $branch->id, 'name' => $name.' '.self::MARK, 'seats' => 4, 'sort_order' => $last + $i + 1,
            ])->id;
        }
    }

    private function inTenant(Tenant $tenant, \Closure $fn): mixed
    {
        if (tenant()?->getTenantKey() === $tenant->getTenantKey()) {
            return $fn();
        }
        tenancy()->initialize($tenant);
        try {
            return $fn();
        } finally {
            tenancy()->end();
            Settings::flush();
            Entitlements::flush();
        }
    }
}
