<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored times move from restaurant wall-clock time (Cairo) to UTC: the app timezone is UTC from now on
 * (config/app.php), so the times written before are converted once.
 *
 * Every datetime / timestamp column of every table, row by row, with the offset Cairo had at that moment (+3 in
 * summer time, +2 otherwise). Date and time columns (expires_on, service_date, opening hours …) are calendar dates
 * and wall-clock hours and stay as they are.
 *
 * The hour that repeats when summer time ends (23:00–24:00 on the last Thursday of October) cannot be told apart
 * by its text. It is read as the first pass (summer time) unless the row shows otherwise: created_at going back
 * within that hour from one row to the next in id order (the clock went back), or a time that would fall before
 * the row's own created_at.
 *
 * MySQL: the app never set a session time zone before, so TIMESTAMP columns are read in the server's zone (as the
 * app wrote them) and written in UTC, the zone the connection uses from now on (config/database.php).
 */
return new class extends Migration
{
    private const PAGE = 500;

    private const FORMAT = 'Y-m-d H:i:s';

    public function up(): void
    {
        $this->convert(true);
    }

    public function down(): void
    {
        $this->convert(false);
    }

    private function convert(bool $toUtc): void
    {
        $zone = new DateTimeZone(local_timezone());
        if ($zone->getName() === 'UTC') {
            return;
        }
        $repeats = $this->repeatedHours($zone);

        foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), false) as $table) {
            $columns = array_values(array_map(fn (array $c) => $c['name'], array_filter(Schema::getColumns($table),
                fn (array $c) => in_array(strtolower($c['type_name']), ['datetime', 'timestamp'], true))));
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? [];
            if ($columns === [] || count($primary) !== 1) {
                continue;
            }
            // created_at first: the other times of a row are resolved against it
            usort($columns, fn (string $a, string $b) => ($b === 'created_at') <=> ($a === 'created_at'));

            DB::transaction(fn () => $this->convertTable($table, $primary[0], $columns, $zone, $repeats, $toUtc));
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array{from: string, to: string, first: int, second: int}>  $repeats
     */
    private function convertTable(string $table, string $key, array $columns, DateTimeZone $zone, array $repeats, bool $toUtc): void
    {
        $passed = []; // per repeated hour: [latest created_at seen in it, the clock has gone back]
        $last = null;
        do {
            $this->sessionZone($toUtc ? 'legacy' : 'utc');
            $rows = DB::table($table)->select(array_merge([$key], $columns))
                ->when($last !== null, fn ($q) => $q->where($key, '>', $last))
                ->orderBy($key)->limit(self::PAGE)->get();
            $this->sessionZone($toUtc ? 'utc' : 'legacy');

            foreach ($rows as $row) {
                $last = $row->{$key};
                $changes = [];
                $createdAt = null;
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d+)?$/', $value, $m)) {
                        continue;
                    }
                    $wall = $m[1].' '.$m[2];
                    if ($toUtc) {
                        [$at, $second, $hour] = $this->toUtc($wall, $zone, $repeats);
                        if ($second !== null && $column === 'created_at') {
                            [$latest, $back] = $passed[$hour] ?? [$wall, false];
                            $back = $back || $wall < $latest;
                            $passed[$hour] = [max($latest, $wall), $back];
                            $at = $back ? $second : $at;
                        } elseif ($second !== null && $createdAt !== null && $at < $createdAt) {
                            $at = $second;
                        }
                    } else {
                        $at = (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->setTimezone($zone);
                    }
                    if ($column === 'created_at') {
                        $createdAt = $at;
                    }
                    $converted = ($toUtc ? $at->setTimezone(new DateTimeZone('UTC')) : $at)->format(self::FORMAT).($m[3] ?? '');
                    if ($converted !== $value) {
                        $changes[$column] = $converted;
                    }
                }
                if ($changes !== []) {
                    DB::table($table)->where($key, $row->{$key})->update($changes);
                }
            }
        } while ($rows->count() === self::PAGE);

        $this->sessionZone('utc');
    }

    /**
     * The UTC moment of a wall-clock time. In a repeated hour also the second pass and that hour's start (the caller
     * picks one), otherwise [moment, null, null].
     *
     * @param  list<array{from: string, to: string, first: int, second: int}>  $repeats
     * @return array{0: DateTimeImmutable, 1: ?DateTimeImmutable, 2: ?string}
     */
    private function toUtc(string $wall, DateTimeZone $zone, array $repeats): array
    {
        foreach ($repeats as $hour) {
            if ($wall >= $hour['from'] && $wall < $hour['to']) {
                $utc = new DateTimeImmutable($wall, new DateTimeZone('UTC'));

                return [$utc->modify(-$hour['first'].' seconds'), $utc->modify(-$hour['second'].' seconds'), $hour['from']];
            }
        }

        return [new DateTimeImmutable($wall, $zone), null, null];
    }

    /**
     * The wall-clock hours that happen twice (summer time ending), with the offsets of their first and second pass.
     *
     * @return list<array{from: string, to: string, first: int, second: int}>
     */
    private function repeatedHours(DateTimeZone $zone): array
    {
        $hours = [];
        $previous = null;
        foreach ($zone->getTransitions(0, time() + 366 * 86400) as $transition) {
            if ($previous !== null && $transition['offset'] < $previous) {
                $hours[] = [
                    'from' => gmdate(self::FORMAT, $transition['ts'] + $transition['offset']),
                    'to' => gmdate(self::FORMAT, $transition['ts'] + $previous),
                    'first' => $previous,
                    'second' => $transition['offset'],
                ];
            }
            $previous = $transition['offset'];
        }

        return $hours;
    }

    /** MySQL TIMESTAMP columns: read and written in the server's own zone (as before) or in UTC (from now on). */
    private function sessionZone(string $zone): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement($zone === 'utc' ? "set time_zone = '+00:00'" : 'set time_zone = @@global.time_zone');
        }
    }
};
