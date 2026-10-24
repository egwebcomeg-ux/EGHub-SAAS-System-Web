<?php

namespace App\Services\Backups;

use App\Models\Concerns\TenantModel;
use App\Models\CorporateVoucher;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\TenantBackup;
use App\Models\User;
use App\Services\Import\MenuSheet;
use App\Services\Import\XlsxWriter;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Writes the backup zip of the current restaurant (tenancy must be initialised for the backup's tenant):
 *
 *  data/{table}.json   every table of the restaurant (each model extending TenantModel), streamed in chunks; orders
 *                      older than N months become a monthly summary (data/orders-archive.json) instead of rows;
 *                      staff come as data/users.json with name / email / phone / role / branch only;
 *  excel/*.xlsx        the menu (in the import layout), customers, recent orders and corporate vouchers;
 *  images/             the restaurant's own uploads (storage/app/public/tenants/{tenant}) up to the size cap;
 *  README.txt          in Arabic: what is inside, how to restore.
 *
 * Only rows carrying the tenant's own tenant_id and files inside its own folder ever go in: every query runs through
 * the tenant scope and the image folder is the tenant's.
 */
class TenantBackupBuilder
{
    /** Columns never written, whatever the table (hashes, secrets, device tokens, one-time codes). */
    private const REDACTED_COLUMNS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'two_factor_confirmed_at', 'manager_pin', 'device_token', 'phone_verification_code'];

    /** table => columns that are secret links to the shop's devices (kiosk / TV screens open with them). */
    private const REDACTED_BY_TABLE = ['kiosks' => ['token'], 'tv_screens' => ['token'], 'pos_terminals' => ['token'], 'pager_tickets' => ['token']];

    /** Settings keys whose value is a credential (the AI key is the restaurant's own paid key). */
    private const REDACTED_SETTINGS = ['ai_key'];

    /** Tables of raw traffic: only the last N months are kept, like the orders. */
    private const RECENT_ONLY = ['menu_item_views', 'menu_visits', 'driver_locations', 'delivery_track_points', 'whatsapp_messages', 'pager_tickets'];

    /** Rows per chunk when streaming a table, and the most rows one Excel sheet gets. */
    private const CHUNK = 500;

    private const SHEET_MAX_ROWS = 50_000;

    /** @var list<string> temp files to delete once the zip is closed (ZipArchive reads them at close()) */
    private array $temps = [];

    /**
     * Builds the zip at the backup's private-disk path and returns what went in.
     *
     * @return array{tables: array<string,int>, images: int, images_skipped: int, orders_archived_months: int, size: int}
     */
    public function build(TenantBackup $backup): array
    {
        $tenantKey = (string) tenant()?->getTenantKey();
        if ($tenantKey === '' || $tenantKey !== (string) $backup->tenant_id) {
            throw new RuntimeException('The backup must be built inside its own restaurant.');
        }
        @set_time_limit(900);

        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($backup->storagePath()));
        $archive = $disk->path($backup->storagePath());
        $cutoff = now()->subMonths(max(1, (int) config('backup.tenant.orders_months')));

        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the backup zip');
        }

        try {
            $summary = ['tables' => [], 'images' => 0, 'images_skipped' => 0, 'orders_archived_months' => 0, 'size' => 0];

            foreach ($this->tenantModels() as $class) {
                $model = new $class;
                $table = $model->getTable();
                $summary['tables'][$table] = $this->addTable($zip, $table, $this->scoped($class::query(), $table, $cutoff));
            }
            $archived = $this->ordersArchive($cutoff);
            $summary['orders_archived_months'] = count($archived);
            $zip->addFromString('data/orders-archive.json', $this->json($archived));
            $summary['tables']['users'] = $this->addStaff($zip);

            $this->addExcel($zip, $cutoff);
            [$summary['images'], $summary['images_skipped']] = $this->addImages($zip, $tenantKey);

            $zip->addFromString('README.txt', $this->readme($backup, $summary, $cutoff));

            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the backup zip');
            }
        } catch (\Throwable $e) {
            try {
                $zip->unchangeAll();
                $zip->close();
            } catch (\Throwable) {
                // the archive was never opened properly or is already closed
            }
            @unlink($archive);
            throw $e;
        } finally {
            foreach ($this->temps as $tmp) {
                @unlink($tmp);
            }
            $this->temps = [];
        }

        $summary['size'] = (int) filesize($archive);

        return $summary;
    }

    /**
     * Every model of the restaurant: the classes under app/Models extending TenantModel (so a table added later is
     * backed up without anyone remembering to list it). The backup rows themselves are left out.
     *
     * @return list<class-string<TenantModel>>
     */
    public function tenantModels(): array
    {
        $classes = [];
        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            if ($class !== TenantBackup::class && class_exists($class) && is_subclass_of($class, TenantModel::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** Orders (and the rows hanging off an order, and raw traffic) are limited to the last N months. */
    private function scoped(Builder $query, string $table, \DateTimeInterface $cutoff): Builder
    {
        if ($table === 'orders') {
            return $query->where('created_at', '>=', $cutoff);
        }
        if (Schema::hasColumn($table, 'order_id')) {
            return $query->whereIn('order_id', Order::query()->where('created_at', '>=', $cutoff)->select('id'));
        }
        if (in_array($table, self::RECENT_ONLY, true) && Schema::hasColumn($table, 'created_at')) {
            return $query->where('created_at', '>=', $cutoff);
        }

        return $query;
    }

    /** Streams one table to data/{table}.json (a JSON array), chunk by chunk, and returns the row count. */
    private function addTable(ZipArchive $zip, string $table, Builder $query): int
    {
        $tmp = $this->temp();
        $out = fopen($tmp, 'w');
        fwrite($out, "[\n");
        $n = 0;
        $query->chunkById(self::CHUNK, function ($rows) use ($out, &$n, $table) {
            foreach ($rows as $row) {
                fwrite($out, ($n ? ",\n" : '').$this->json($this->clean($row, $table)));
                $n++;
            }
        });
        fwrite($out, "\n]\n");
        fclose($out);
        $zip->addFile($tmp, "data/$table.json");

        return $n;
    }

    /** The row as an array with every secret column removed. @return array<string,mixed> */
    private function clean(Model $row, string $table): array
    {
        $data = $row->attributesToArray();
        foreach ([...self::REDACTED_COLUMNS, ...(self::REDACTED_BY_TABLE[$table] ?? [])] as $column) {
            unset($data[$column]);
        }
        if ($table === 'tenant_settings' && in_array($data['key'] ?? '', self::REDACTED_SETTINGS, true) && ($data['value'] ?? '') !== '') {
            $data['value'] = '[redacted]';
        }

        return $data;
    }

    /**
     * Staff accounts: who works here and in which role, nothing that signs in. Never the password hash, the remember
     * token, the 2FA secret, the manager PIN or the phone verification code.
     */
    private function addStaff(ZipArchive $zip): int
    {
        $rows = [];
        User::query()->with(['roles:id,name', 'permissions:id,name'])
            ->chunkById(self::CHUNK, function ($users) use (&$rows) {
                foreach ($users as $u) {
                    $rows[] = [
                        'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
                        'role' => $u->roleKey(), 'extra_permissions' => $u->permissions->pluck('name')->values()->all(),
                        'branch_id' => $u->branch_id, 'is_active' => (bool) $u->is_active, 'locale' => $u->locale,
                        'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String(),
                    ];
                }
            });
        $zip->addFromString('data/users.json', $this->json($rows));

        return count($rows);
    }

    /**
     * Orders before the cutoff, one line per month: how many, how many cancelled, the sales total.
     *
     * @return list<array{month: string, orders: int, cancelled: int, total: float}>
     */
    private function ordersArchive(\DateTimeInterface $cutoff): array
    {
        $month = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        $rows = Order::query()->where('created_at', '<', $cutoff)
            ->selectRaw("$month as month, COUNT(*) as orders, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN status = 'cancelled' THEN 0 ELSE total END) as total")
            ->groupByRaw($month)->orderByRaw($month)->get();

        return $rows->map(fn ($r) => ['month' => (string) $r->month, 'orders' => (int) $r->orders, 'cancelled' => (int) $r->cancelled, 'total' => round((float) $r->total, 2)])->values()->all();
    }

    /** The human-readable sheets: menu (import layout), customers, recent orders, corporate vouchers. */
    private function addExcel(ZipArchive $zip, \DateTimeInterface $cutoff): void
    {
        $this->addSheet($zip, 'excel/menu.xlsx', 'Menu', MenuSheet::rows());

        $customers = [['الاسم', 'الموبايل', 'عدد الزيارات', 'إجمالي المصروف', 'آخر زيارة', 'تاريخ الميلاد', 'موافق على العروض', 'رصيد النقاط', 'تاريخ الإضافة']];
        Customer::query()->chunkById(self::CHUNK, function ($rows) use (&$customers) {
            foreach ($rows as $c) {
                if (count($customers) > self::SHEET_MAX_ROWS) {
                    return false;
                }
                $customers[] = [$c->name, $c->phone, (int) $c->visits_count, (float) $c->total_spent, $c->last_visit_at?->format('Y-m-d'),
                    $c->birthday?->format('Y-m-d'), $c->marketing_opt_in ? 'نعم' : 'لا', (int) $c->points_balance, $c->created_at?->format('Y-m-d')];
            }
        });
        $this->addSheet($zip, 'excel/customers.xlsx', 'Customers', $customers);

        $orders = [['رقم الطلب', 'التاريخ', 'الفرع', 'النوع', 'المصدر', 'الحالة', 'اسم العميل', 'موبايل العميل', 'الأصناف', 'قبل الخصم', 'الخصم', 'الخدمة', 'الضريبة', 'الإجمالي', 'حالة الدفع']];
        Order::query()->with(['branch:id,name', 'items:id,order_id,name,quantity'])->where('created_at', '>=', $cutoff)
            ->chunkById(self::CHUNK, function ($rows) use (&$orders) {
                foreach ($rows as $o) {
                    if (count($orders) > self::SHEET_MAX_ROWS) {
                        return false;
                    }
                    $orders[] = [(int) $o->number, local_time($o->created_at, 'Y-m-d H:i'), (string) $o->branch?->name,
                        Order::TYPES[$o->type][0] ?? (string) $o->type, Order::SOURCES[$o->source][0] ?? (string) $o->source, Order::STATUSES[$o->status][0] ?? (string) $o->status,
                        (string) $o->customer_name, (string) $o->customer_phone,
                        $o->items->map(fn ($i) => $i->quantity.'× '.$i->name)->implode(' | '),
                        (float) $o->subtotal, (float) $o->discount, (float) $o->service, (float) $o->tax, (float) $o->total, $o->payment_status === 'paid' ? 'مدفوع' : 'غير مدفوع'];
                }
            });
        $this->addSheet($zip, 'excel/orders.xlsx', 'Orders', $orders);

        $vouchers = [['الشركة', 'السيريال', 'الكود', 'القيمة', 'الرصيد', 'الحالة', 'ينتهي في', 'مرتبط بعميل', 'تاريخ الإصدار']];
        CorporateVoucher::query()->with(['contract:id,name', 'customer:id,phone'])->chunkById(self::CHUNK, function ($rows) use (&$vouchers) {
            foreach ($rows as $v) {
                if (count($vouchers) > self::SHEET_MAX_ROWS) {
                    return false;
                }
                $vouchers[] = [(string) $v->contract?->name, (string) $v->serial, $v->formattedCode(), (float) $v->value, (float) $v->balance,
                    (string) $v->status, $v->expires_on?->format('Y-m-d'), (string) ($v->customer?->phone ?? ''), $v->created_at?->format('Y-m-d')];
            }
        });
        $this->addSheet($zip, 'excel/corporate-vouchers.xlsx', 'Vouchers', $vouchers);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function addSheet(ZipArchive $zip, string $name, string $sheet, array $rows): void
    {
        $tmp = $this->temp();
        (new XlsxWriter)->addSheet($sheet, $rows)->save($tmp);
        $zip->addFile($tmp, $name);
    }

    /**
     * The restaurant's uploads (item and category photos, logo, cover, banners, videos) from its own folder on the
     * public disk, oldest first, until the size cap. @return array{0: int, 1: int} [added, skipped]
     */
    private function addImages(ZipArchive $zip, string $tenantKey): array
    {
        $root = Storage::disk('public')->path('tenants/'.$tenantKey);
        if (! is_dir($root)) {
            return [0, 0];
        }
        $cap = max(1, (int) config('backup.tenant.images_max_mb')) * 1024 * 1024;
        $files = File::allFiles($root, true);
        usort($files, fn ($a, $b) => [$a->getMTime(), $a->getPathname()] <=> [$b->getMTime(), $b->getPathname()]);
        $added = $skipped = 0;
        $bytes = 0;
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            // the import folder holds files the AI import is still reading, not the menu's media
            if (str_starts_with($relative, 'imports/') || $bytes + $file->getSize() > $cap) {
                $skipped += str_starts_with($relative, 'imports/') ? 0 : 1;

                continue;
            }
            $zip->addFile($file->getPathname(), 'images/'.$relative);
            $zip->setCompressionName('images/'.$relative, ZipArchive::CM_STORE);
            $bytes += $file->getSize();
            $added++;
        }

        return [$added, $skipped];
    }

    /** @param array{tables: array<string,int>, images: int, images_skipped: int, orders_archived_months: int} $summary */
    private function readme(TenantBackup $backup, array $summary, \DateTimeInterface $cutoff): string
    {
        $name = Settings::get('brand_name') ?: (string) tenant()->name;
        $months = max(1, (int) config('backup.tenant.orders_months'));
        $support = trim((string) PlatformSetting::get('support_whatsapp', ''));
        $lines = [
            'نسخة احتياطية من بيانات «'.$name.'» على EgHub',
            'المطعم (الرابط): '.$backup->tenant_id,
            'تاريخ النسخة: '.local_time(now(), 'Y/m/d h:i A').' (توقيت القاهرة)',
            'نوع النسخة: '.($backup->kind === TenantBackup::KIND_SCHEDULED ? 'أسبوعية (تلقائية)' : 'يدوية (من لوحة التحكم)'),
            '',
            'إيه اللي جوه النسخة؟',
            '-------------------',
            'data/        كل جداول المطعم بصيغة JSON (الإعدادات، الأقسام، الأصناف، الإضافات، الترابيزات، الفروع، الطلبات وأصنافها ومرتجعاتها، العملاء، الولاء، الكوبونات، تعاقدات الشركات، الحجوزات، التقييمات، الحملات، سجل العمليات).',
            '             الطلبات الأقدم من '.$months.' شهر (قبل '.local_time($cutoff, 'Y/m/d').') موجودة كملخص شهري في data/orders-archive.json بدل صف لكل طلب، عشان الملف يفضل صغير.',
            '             data/users.json: فريق العمل بالاسم والإيميل والدور والفرع فقط. مفيش أي كلمات سر أو أكواد دخول في النسخة.',
            'excel/       ملفات Excel سهلة القراءة: المنيو (menu.xlsx)، العملاء (customers.xlsx)، طلبات آخر '.$months.' شهر (orders.xlsx)، فاوتشرات الشركات (corporate-vouchers.xlsx).',
            'images/      صور الأصناف والأقسام واللوجو والكفر والبانرات اللي رفعتها على EgHub'.($summary['images'] ? ' ('.$summary['images'].' ملف).' : ' (مفيش صور مرفوعة).'),
        ];
        if ($summary['images_skipped'] > 0) {
            $lines[] = '             تنبيه: '.$summary['images_skipped'].' ملف ما اتضافش لأن حجم الصور عدّى الحد الأقصى ('.(int) config('backup.tenant.images_max_mb').' ميجا). الصور دي موجودة على السيرفر، واطلبها من دعم EgWeb لو احتجتها.';
        }
        $lines = [...$lines, '',
            'عدد الصفوف في كل جدول:',
            '--------------------',
        ];
        foreach ($summary['tables'] as $table => $n) {
            $lines[] = str_pad($table, 32).$n;
        }
        $lines = [...$lines, '',
            'إزاي أرجّع المنيو؟',
            '----------------',
            '1. من لوحة التحكم: المنيو ← استيراد من Excel.',
            '2. ارفع ملف excel/menu.xlsx زي ما هو (نفس تنسيق قالب الاستيراد)، وراجع الصفوف، واختار «إضافة» أو «تحديث».',
            '3. الصور بترجع من مجلد images/ (بترفعها على كل صنف من صفحة المنيو).',
            '',
            'إزاي أرجّع كل حاجة (الطلبات والعملاء والولاء والمحفظة...)؟',
            '----------------------------------------------------',
            'الاسترجاع الكامل بيتم عن طريق دعم EgWeb: ابعت لنا ملف النسخة ده (أو تاريخه) على واتساب الدعم'.($support !== '' ? ' ('.$support.')' : '').' أو من صفحة المساعدة في لوحة التحكم، وإحنا بنرجّع البيانات على حسابك.',
            'ملفات data/*.json مكتوبة بنفس أسماء الجداول والأعمدة الموجودة على المنصة.',
            '',
            'ملاحظات',
            '-------',
            '- النسخة دي بتاعة مطعمك إنت بس: مفيش فيها أي بيانات لمطاعم أخرى.',
            '- احتفظ بالملف في مكان آمن: فيه أرقام موبايلات عملائك.',
            '- EgHub بتعمل كمان نسخة احتياطية يومية لكل المنصة على السيرفر، النسخة دي زيادة في إيدك إنت.',
            '',
            'EgHub · by EgWeb · eghub.app',
        ];

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    private function temp(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'egtb');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file');
        }
        $this->temps[] = $tmp;

        return $tmp;
    }
}
