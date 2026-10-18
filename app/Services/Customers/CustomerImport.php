<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guest list import from a sheet (CSV / .xlsx read by SpreadsheetReader). Rows are matched by mobile inside the
 * current restaurant only (the tenant scope of Customer), so the same number in another restaurant is a new guest
 * here. Counters (visits, spend), loyalty and wallet balances are never written.
 */
final class CustomerImport
{
    public const MAX_ROWS = 5000;

    public const MAX_KILOBYTES = 2048;

    public const MODES = ['keep', 'fill', 'overwrite'];

    private const CHUNK = 500;

    private const MAX_ADDRESSES = 5;

    /** @var Collection<int,DeliveryZone>|null the restaurant's delivery zones, loaded once per import */
    private ?Collection $zones = null;

    /**
     * field => accepted headers (Arabic and English). The export's own headers are added in columns(); its computed
     * columns (visits, spend, last visit, segment) are not read. There is no email column: an email goes into the notes.
     */
    private const HEADERS = [
        'name' => ['name', 'full name', 'customer', 'customer name', 'الاسم', 'اسم', 'اسم العميل', 'العميل', 'الاسم بالكامل'],
        'phone' => ['mobile', 'phone', 'mobile number', 'phone number', 'tel', 'whatsapp', 'الموبايل', 'موبايل', 'رقم الموبايل', 'الموبيل', 'موبيل',
            'التليفون', 'تليفون', 'رقم التليفون', 'الهاتف', 'رقم الهاتف', 'الجوال', 'واتساب', 'رقم الواتساب'],
        'phone_2' => ['second phone', 'phone 2', 'mobile 2', 'other phone', 'second mobile', 'الموبايل التاني', 'موبايل تاني', 'رقم تاني',
            'تليفون تاني', 'الرقم التاني', 'موبايل 2', 'تليفون 2', 'رقم آخر', 'هاتف آخر'],
        'email' => ['email', 'e-mail', 'mail', 'الإيميل', 'الايميل', 'البريد', 'البريد الإلكتروني'],
        'birthday' => ['birthday', 'birth date', 'date of birth', 'dob', 'عيد الميلاد', 'تاريخ الميلاد', 'الميلاد'],
        'notes' => ['notes', 'note', 'comments', 'ملاحظات', 'ملاحظة', 'ملحوظات'],
        'marketing_opt_in' => ['accepts offers', 'marketing', 'opt in', 'offers', 'موافق على العروض', 'يقبل العروض', 'العروض'],
        'address' => ['address', 'street', 'العنوان', 'عنوان', 'الشارع'],
        'area' => ['area', 'zone', 'district', 'delivery zone', 'المنطقة', 'منطقة', 'الحي', 'منطقة التوصيل'],
    ];

    /** Columns of the example file, in the order of the customers export so an export imports back as is. */
    public static function templateHeader(): array
    {
        return [__('Name'), __('Mobile'), __('Visits'), __('Total spent'), __('Last visit'), __('Birthday'), __('Accepts offers'), __('Segment'),
            __('Second phone'), __('Notes'), __('Address'), __('Area')];
    }

    /**
     * Reads the sheet rows into guests: header mapping, mobile normalising, duplicates in the file, then each guest is
     * marked new or existing (in this restaurant) with what "fill empty" and "overwrite" would change.
     *
     * @param  array<int,array<int,string>>  $rows  first row = header
     * @return array{columns: array<string,int>, guests: array<int,array<string,mixed>>, skipped: array<int,array{line:int,reason:string,value:string}>, counts: array{new:int,existing:int,skipped:int,fill:int,overwrite:int}, ignored: string[]}
     *
     * @throws \RuntimeException
     */
    public function analyse(array $rows): array
    {
        if (count($rows) < 2) {
            throw new \RuntimeException(__('The file has no customer rows.'));
        }
        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new \RuntimeException(__('The file has more than :n rows. Split it into smaller files.', ['n' => number_format(self::MAX_ROWS)]));
        }
        [$columns, $ignored] = $this->columns($rows[0]);
        if (! isset($columns['phone'])) {
            throw new \RuntimeException(__('We could not find the mobile column. Name it "Mobile" (الموبايل) like the example file.'));
        }

        $guests = [];
        $skipped = [];
        $seen = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            $line = $i + 2;
            $cell = fn (string $field): string => isset($columns[$field]) ? trim((string) ($row[$columns[$field]] ?? '')) : '';
            $values = array_map($cell, array_combine(array_keys($columns), array_keys($columns)));
            if (! array_filter($values, fn ($v) => $v !== '')) {
                $skipped[] = ['line' => $line, 'reason' => __('Empty row'), 'value' => ''];

                continue;
            }
            $rawPhone = $values['phone'] ?? '';
            if ($rawPhone === '') {
                $skipped[] = ['line' => $line, 'reason' => __('No mobile number'), 'value' => $values['name'] ?? ''];

                continue;
            }
            $phone = self::phone($rawPhone);
            if ($phone === null) {
                $skipped[] = ['line' => $line, 'reason' => __('Not an Egyptian mobile number'), 'value' => $rawPhone];

                continue;
            }
            if (isset($seen[$phone])) {
                $skipped[] = ['line' => $line, 'reason' => __('Repeated in the file (line :line)', ['line' => $seen[$phone]]), 'value' => $phone];

                continue;
            }
            $seen[$phone] = $line;
            $guests[] = ['line' => $line, 'phone' => $phone] + $this->fields($values);
        }

        $this->matchExisting($guests);
        $existing = array_filter($guests, fn ($g) => $g['customer_id'] !== null);

        return [
            'columns' => $columns,
            'guests' => $guests,
            'skipped' => $skipped,
            'ignored' => $ignored,
            'counts' => [
                'new' => count($guests) - count($existing),
                'existing' => count($existing),
                'skipped' => count($skipped),
                'fill' => count(array_filter($existing, fn ($g) => $g['changes']['fill'] !== [])),
                'overwrite' => count(array_filter($existing, fn ($g) => $g['changes']['overwrite'] !== [])),
            ],
        ];
    }

    /**
     * Saves the analysed guests: new ones are added, existing ones follow $mode (keep / fill empty / overwrite).
     * The matching runs again inside the transaction, so a guest who ordered since the review is not duplicated.
     *
     * @param  array<int,array<string,mixed>>  $guests
     * @return array{created:int, updated:int, unchanged:int}
     */
    public function save(array $guests, string $mode): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'keep';
        $tenantId = tenant()->getTenantKey();

        return DB::transaction(function () use ($guests, $mode, $tenantId) {
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach (array_chunk($guests, self::CHUNK) as $chunk) {
                $existing = Customer::query()->whereIn('phone', array_column($chunk, 'phone'))->get()->keyBy('phone');
                $insert = [];
                foreach ($chunk as $guest) {
                    $customer = $existing->get($guest['phone']);
                    if ($customer === null) {
                        $insert[] = $this->newRow($guest, $tenantId);

                        continue;
                    }
                    $changes = $mode === 'keep' ? [] : $this->changes($customer, $guest)[$mode];
                    if ($changes === []) {
                        $result['unchanged']++;

                        continue;
                    }
                    // only profile fields: visits, spend, points, loyalty and wallet are never part of $changes
                    $customer->forceFill($changes)->save();
                    $result['updated']++;
                }
                if ($insert !== []) {
                    $result['created'] += DB::table('customers')->insertOrIgnore($insert);
                }
            }

            return $result;
        });
    }

    /**
     * Excel turns 01012345678 into the number 1012345678 (or 1.012345678E+9 / 1012345678.0); normalize_phone() then
     * adds the zero back and reads Arabic-Indic digits.
     */
    public static function phone(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $raw)) {
            $raw = number_format((float) $raw, 0, '', '');
        } elseif (preg_match('/^(\d+)\.0+$/', $raw, $m)) {
            $raw = $m[1];
        }

        return normalize_phone($raw);
    }

    /**
     * @param  array<int,string>  $header
     * @return array{0: array<string,int>, 1: string[]}
     */
    private function columns(array $header): array
    {
        $aliases = self::HEADERS;
        // the export's headers in both languages, so an export from either dashboard language imports back
        foreach (['name' => 'Name', 'phone' => 'Mobile', 'birthday' => 'Birthday', 'marketing_opt_in' => 'Accepts offers',
            'phone_2' => 'Second phone', 'notes' => 'Notes', 'address' => 'Address', 'area' => 'Area'] as $field => $key) {
            $aliases[$field][] = mb_strtolower($key);
            $aliases[$field][] = mb_strtolower(trans($key, [], 'ar'));
        }
        $columns = [];
        $ignored = [];
        foreach ($header as $index => $title) {
            $key = self::headerKey((string) $title);
            $field = collect($aliases)->search(fn (array $names) => in_array($key, array_map(self::headerKey(...), $names), true));
            if ($field !== false && ! isset($columns[$field])) {
                $columns[$field] = $index;
            } elseif (trim((string) $title) !== '') {
                $ignored[] = trim((string) $title);
            }
        }

        return [$columns, $ignored];
    }

    private static function headerKey(string $title): string
    {
        $title = preg_replace('/^\xEF\xBB\xBF/', '', $title) ?? $title;
        $title = strtr(mb_strtolower(trim($title)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', '_' => ' ', '-' => ' ', ':' => '', '*' => '']);

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    /**
     * Clean profile fields of one row; empty cells stay null (= nothing to write).
     *
     * @param  array<string,string>  $values
     * @return array<string,mixed>
     */
    private function fields(array $values): array
    {
        $notes = trim(implode("\n", array_filter([
            $values['notes'] ?? '',
            ($values['email'] ?? '') !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) ? __('Email').': '.$values['email'] : '',
        ])));
        $phone2 = $values['phone_2'] ?? '';
        $address = trim($values['address'] ?? '');
        $area = trim($values['area'] ?? '');

        return [
            'name' => ($n = mb_substr(trim($values['name'] ?? ''), 0, 100)) !== '' ? $n : null,
            'phone_2' => $phone2 !== '' ? (self::phone($phone2) ?? mb_substr($phone2, 0, 20)) : null,
            'birthday' => self::date($values['birthday'] ?? ''),
            'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
            'marketing_opt_in' => self::yes($values['marketing_opt_in'] ?? ''),
            'address' => $address !== '' || $area !== '' ? $this->address($address, $area) : null,
        ];
    }

    /** @return array{label:string, zone_id:int|null, text:string} */
    private function address(string $text, string $area): array
    {
        $zone = null;
        if ($area !== '') {
            $this->zones ??= DeliveryZone::query()->with('parent')->get();
            $key = self::headerKey($area);
            $zone = $this->zones->first(fn (DeliveryZone $z) => in_array($key, array_map(self::headerKey(...), array_filter([$z->name, $z->name_en, $z->label()])), true));
        }
        if (! $zone && $area !== '') {
            $text = trim($text.($text !== '' ? ' - ' : '').$area);
        }

        return ['label' => '', 'zone_id' => $zone?->id, 'text' => mb_substr($text, 0, 255)];
    }

    /** Dates as 2000-05-31, 31/05/2000, 31-5-2000, or an Excel day number; anything else (or in the future) is dropped. */
    private static function date(string $raw): ?string
    {
        $raw = trim(strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if ($raw === '') {
            return null;
        }
        try {
            $date = match (true) {
                (bool) preg_match('/^\d{5}(\.\d+)?$/', $raw) && (int) $raw < 80000 => Carbon::create(1899, 12, 30)->addDays((int) $raw),
                (bool) preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $raw, $m) => Carbon::createSafe((int) $m[1], (int) $m[2], (int) $m[3]),
                (bool) preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $raw, $m) => Carbon::createSafe((int) $m[3], (int) $m[2], (int) $m[1]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->isPast() && $date->year >= 1900 ? $date->format('Y-m-d') : null;
    }

    private static function yes(string $raw): ?bool
    {
        $raw = mb_strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        return in_array($raw, ['1', 'yes', 'y', 'true', 'نعم', 'اه', 'آه', 'أيوه', 'ايوه', 'موافق', '✓', 'x'], true);
    }

    /** Marks each guest new or existing here, with the changes each update mode would make. */
    private function matchExisting(array &$guests): void
    {
        foreach (array_chunk(array_keys($guests), self::CHUNK) as $keys) {
            $existing = Customer::query()->whereIn('phone', array_map(fn ($k) => $guests[$k]['phone'], $keys))->get()->keyBy('phone');
            foreach ($keys as $k) {
                $customer = $existing->get($guests[$k]['phone']);
                $guests[$k]['customer_id'] = $customer?->id;
                $guests[$k]['current_name'] = $customer?->name;
                $guests[$k]['changes'] = $customer ? array_map('array_keys', $this->changes($customer, $guests[$k])) : ['fill' => [], 'overwrite' => []];
            }
        }
    }

    /**
     * What "fill empty only" and "overwrite" would write on an existing guest. Saved addresses are never removed:
     * fill adds one only when the guest has none, overwrite puts the file's address first.
     *
     * @return array{fill: array<string,mixed>, overwrite: array<string,mixed>}
     */
    private function changes(Customer $customer, array $guest): array
    {
        $fill = [];
        $overwrite = [];
        foreach (['name', 'phone_2', 'birthday', 'notes', 'marketing_opt_in'] as $field) {
            $new = $guest[$field] ?? null;
            if ($new === null) {
                continue;
            }
            $current = $field === 'birthday' ? $customer->birthday?->format('Y-m-d') : $customer->{$field};
            if ($current === $new) {
                continue;
            }
            $overwrite[$field] = $new;
            if ($current === null || $current === '' || $current === false) {
                $fill[$field] = $new;
            }
        }
        if (! empty($guest['address'])) {
            $saved = collect($customer->addresses ?? []);
            $address = $guest['address'];
            $same = fn ($a) => ($a['text'] ?? '') === $address['text'] && (int) ($a['zone_id'] ?? 0) === (int) ($address['zone_id'] ?? 0);
            if ($saved->isEmpty()) {
                $fill['addresses'] = $overwrite['addresses'] = [$address];
            } elseif (! $same($saved->first())) {
                $overwrite['addresses'] = $saved->reject($same)->prepend($address)->take(self::MAX_ADDRESSES)->values()->all();
            }
        }

        return ['fill' => $fill, 'overwrite' => $overwrite];
    }

    /** @return array<string,mixed> */
    private function newRow(array $guest, string|int $tenantId): array
    {
        $now = now();

        return [
            'tenant_id' => $tenantId,
            'name' => $guest['name'] ?? '',
            'phone' => $guest['phone'],
            'phone_2' => $guest['phone_2'] ?? null,
            'birthday' => $guest['birthday'] ?? null,
            'notes' => $guest['notes'] ?? null,
            'marketing_opt_in' => (bool) ($guest['marketing_opt_in'] ?? false),
            'addresses' => ! empty($guest['address']) ? json_encode([$guest['address']], JSON_UNESCAPED_UNICODE) : null,
            'source' => 'import',
            'card_token' => random_token(12),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
