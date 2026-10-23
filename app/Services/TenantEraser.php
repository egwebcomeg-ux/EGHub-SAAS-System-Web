<?php

namespace App\Services;

use App\Models\Concerns\TenantModel;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a restaurant for good (the super admin's "delete permanently"), the opposite of TenantProvisioner: every
 * row of it in every table that has a tenant_id (found from the schema and from the TenantModel classes), children
 * before their parents by the foreign keys (orders before branches, ...), the rows of other tables that point at
 * them (payments, add-ons, roles and permissions of its staff, sessions, reset tokens), its domains and the tenant
 * row, then its folders: public/tenants/{id}, private/tenants/{id} and private/backups/{id}. Another restaurant's
 * rows are never touched: every delete is filtered by this tenant's id or by ids read under it.
 */
final class TenantEraser
{
    /** A restaurant that paid within these days is only deleted when forced. */
    public const RECENT_PAYMENT_DAYS = 30;

    public function paidRecently(Tenant $tenant): bool
    {
        return Invoice::query()->where('tenant_id', $tenant->getTenantKey())->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(self::RECENT_PAYMENT_DAYS))->exists();
    }

    /**
     * @return array{rows: array<string, int>, folders: list<string>}
     *
     * @throws \DomainException when it paid within RECENT_PAYMENT_DAYS and $force is off
     */
    public function erase(Tenant $tenant, bool $force = false): array
    {
        if (! $force && $this->paidRecently($tenant)) {
            throw new \DomainException(__('This restaurant paid an invoice in the last :days days. Tick "delete anyway" to delete it.', ['days' => self::RECENT_PAYMENT_DAYS]));
        }
        $id = (string) $tenant->getTenantKey();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $tables = $this->tenantTables();
        $rows = [];

        DB::transaction(function () use ($id, $tables, &$rows) {
            // its staff: role and permission links, sessions and password reset tokens carry no tenant_id
            $users = DB::table('users')->where('tenant_id', $id);
            $userIds = (clone $users)->pluck('id');
            $emails = (clone $users)->whereNotNull('email')->pluck('email');
            $morph = (new User)->getMorphClass();
            foreach ([config('permission.table_names.model_has_roles', 'model_has_roles'), config('permission.table_names.model_has_permissions', 'model_has_permissions')] as $pivot) {
                if (Schema::hasTable($pivot)) {
                    $rows[$pivot] = DB::table($pivot)->where('model_type', $morph)->whereIn(config('permission.column_names.model_morph_key', 'model_id'), $userIds)->delete();
                }
            }
            if (Schema::hasTable('sessions')) {
                $rows['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            }
            if (Schema::hasTable('password_reset_tokens')) {
                $rows['password_reset_tokens'] = DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            }

            foreach ($this->deleteOrder($tables) as $table) {
                $this->deletePointingRows($table, $id, $tables, $rows);
                // a column pointing at its own table (a sub-zone's parent) is cleared first, so the order inside it does not matter
                foreach ($this->foreignKeys($table) as $fk) {
                    if ($fk['foreign_table'] === $table && $fk['nullable']) {
                        DB::table($table)->where('tenant_id', $id)->update([$fk['column'] => null]);
                    }
                }
                $rows[$table] = DB::table($table)->where('tenant_id', $id)->delete();
            }
            $rows['tenants'] = DB::table('tenants')->where('id', $id)->delete();
        });

        $folders = [];
        foreach ([['public', "tenants/$id"], ['local', "tenants/$id"], ['local', "backups/$id"]] as [$disk, $dir]) {
            // the rows are gone already: a file the system will not let go of (locked on Windows) is logged, not fatal
            try {
                if (Storage::disk($disk)->exists($dir)) {
                    Storage::disk($disk)->deleteDirectory($dir);
                    $folders[] = "$disk:$dir";
                }
            } catch (\Throwable $e) {
                report($e);
                $folders[] = "$disk:$dir (not removed)";
            }
        }
        Entitlements::flush();

        return ['rows' => array_filter($rows), 'folders' => $folders];
    }

    /**
     * Every table with restaurant rows: the tables of the TenantModel classes and any other table with a tenant_id
     * column (users, subscriptions, invoices, domains, ...).
     *
     * @return list<string>
     */
    public function tenantTables(): array
    {
        $tables = [];
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, TenantModel::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $tables[] = (new $class)->getTable();
            }
        }
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $tables[] = $table;
        }

        return array_values(array_filter(array_unique($tables),
            fn (string $t) => $t !== 'tenants' && Schema::hasTable($t) && Schema::hasColumn($t, 'tenant_id')));
    }

    /**
     * Children before parents: a table is deleted once no other remaining table points at it. Tables in a cycle
     * have their nullable cross references cleared by deletePointingRows and go last.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function deleteOrder(array $tables): array
    {
        $parents = [];
        foreach ($tables as $table) {
            $parents[$table] = array_values(array_unique(array_filter(
                array_column($this->foreignKeys($table), 'foreign_table'),
                fn (string $p) => $p !== $table && in_array($p, $tables, true),
            )));
        }
        $order = [];
        $left = $tables;
        while ($left !== []) {
            // a table nobody left points at can go now
            $free = array_values(array_filter($left, function (string $t) use ($left, $parents) {
                foreach ($left as $other) {
                    if ($other !== $t && in_array($t, $parents[$other], true)) {
                        return false;
                    }
                }

                return true;
            }));
            if ($free === []) {
                return [...$order, ...$left];
            }
            $order = [...$order, ...$free];
            $left = array_values(array_diff($left, $free));
        }

        return $order;
    }

    /**
     * Before $table's rows go: rows of tables without a tenant_id that point at them are deleted (payments of its
     * invoices, add-ons of its subscription, social logins of its staff) or let go of them where their key is "set
     * null" on delete, and nullable references from the other
     * restaurant tables still left are cleared (a cycle between two of its tables).
     *
     * @param  list<string>  $tenantTables
     * @param  array<string, int>  $rows
     */
    private function deletePointingRows(string $table, string $id, array $tenantTables, array &$rows): void
    {
        foreach (Schema::getTableListing(schemaQualified: false) as $other) {
            if ($other === $table || $other === 'tenants') {
                continue;
            }
            foreach ($this->foreignKeys($other) as $fk) {
                if ($fk['foreign_table'] !== $table) {
                    continue;
                }
                $ids = DB::table($table)->where('tenant_id', $id)->select($fk['foreign_column']);
                if (! in_array($other, $tenantTables, true) && $fk['on_delete'] === 'set null') {
                    DB::table($other)->whereIn($fk['column'], $ids)->update([$fk['column'] => null]);
                } elseif (! in_array($other, $tenantTables, true)) {
                    $rows[$other] = ($rows[$other] ?? 0) + DB::table($other)->whereIn($fk['column'], $ids)->delete();
                } elseif ($fk['nullable']) {
                    DB::table($other)->where('tenant_id', $id)->whereIn($fk['column'], $ids)->update([$fk['column'] => null]);
                }
            }
        }
    }

    /** @return list<array{column:string, foreign_table:string, foreign_column:string, nullable:bool, on_delete:string}> */
    private function foreignKeys(string $table): array
    {
        static $memo = [];
        if (! isset($memo[$table])) {
            $nullable = collect(Schema::getColumns($table))->pluck('nullable', 'name');
            $memo[$table] = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    continue;
                }
                $memo[$table][] = [
                    'column' => $fk['columns'][0], 'foreign_table' => preg_replace('/^.*\./', '', (string) $fk['foreign_table']),
                    'foreign_column' => $fk['foreign_columns'][0] ?? 'id', 'nullable' => (bool) ($nullable[$fk['columns'][0]] ?? false),
                    'on_delete' => strtolower((string) ($fk['on_delete'] ?? '')),
                ];
            }
        }

        return $memo[$table];
    }
}
