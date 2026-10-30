<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The one-off migration that moves the times stored in Cairo wall-clock time to UTC. */
class ConvertStoredTimesToUtcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('utc_probe', function (Blueprint $t) {
            $t->id();
            $t->string('label');
            $t->timestamp('served_at')->nullable();
            $t->date('service_date')->nullable();
            $t->time('opens_at')->nullable();
            $t->timestamps();
        });
        $this->legacySession();
    }

    /** On MySQL the app used to read and write in the server's own zone (no session zone was set): the rows written before. */
    private function legacySession(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('set time_zone = @@global.time_zone');
        }
    }

    /** @param  array<string, ?string>  $times */
    private function row(string $label, ?string $createdAt, array $times = []): void
    {
        DB::table('utc_probe')->insert(['label' => $label, 'created_at' => $createdAt, 'updated_at' => $createdAt] + $times);
    }

    /** @return array<string, array{created_at: ?string, updated_at: ?string, served_at: ?string}> */
    private function stored(): array
    {
        return DB::table('utc_probe')->orderBy('id')->get()
            ->mapWithKeys(fn ($r) => [$r->label => ['created_at' => $r->created_at, 'updated_at' => $r->updated_at, 'served_at' => $r->served_at]])->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_16_000100_convert_stored_times_to_utc.php');
    }

    public function test_each_time_moves_by_the_offset_cairo_had_at_that_moment(): void
    {
        $this->row('winter', '2026-01-15 12:00:00', ['served_at' => '2026-01-15 12:20:00', 'service_date' => '2026-01-15', 'opens_at' => '09:00:00']);
        $this->row('summer', '2026-07-01 12:00:00');
        $this->row('before summer time starts', '2026-04-23 23:30:00');
        $this->row('after summer time starts', '2026-04-24 01:30:00');
        $this->row('after midnight', '2026-07-02 01:30:00');
        $this->row('never served', null);

        $this->migration()->up();

        $this->assertSame([
            'winter' => ['created_at' => '2026-01-15 10:00:00', 'updated_at' => '2026-01-15 10:00:00', 'served_at' => '2026-01-15 10:20:00'],
            'summer' => ['created_at' => '2026-07-01 09:00:00', 'updated_at' => '2026-07-01 09:00:00', 'served_at' => null],
            'before summer time starts' => ['created_at' => '2026-04-23 21:30:00', 'updated_at' => '2026-04-23 21:30:00', 'served_at' => null],
            'after summer time starts' => ['created_at' => '2026-04-23 22:30:00', 'updated_at' => '2026-04-23 22:30:00', 'served_at' => null],
            'after midnight' => ['created_at' => '2026-07-01 22:30:00', 'updated_at' => '2026-07-01 22:30:00', 'served_at' => null],
            'never served' => ['created_at' => null, 'updated_at' => null, 'served_at' => null],
        ], $this->stored());
        // calendar dates and opening hours are wall-clock values: untouched
        $winter = DB::table('utc_probe')->where('label', 'winter')->first();
        $this->assertSame(['2026-01-15', '09:00:00'], [$winter->service_date, $winter->opens_at]);
    }

    public function test_the_repeated_hour_follows_the_order_the_rows_were_written_in(): void
    {
        // summer time ends at midnight on Thursday 29 October 2026: 23:00–24:00 happens twice (UTC+3, then UTC+2)
        $this->row('first pass', '2026-10-29 23:10:00');
        $this->row('first pass, later', '2026-10-29 23:50:00');
        $this->row('clock went back', '2026-10-29 23:05:00', ['served_at' => '2026-10-29 23:25:00']);
        $this->row('second pass', '2026-10-29 23:40:00');
        $this->row('next day', '2026-10-30 00:10:00');
        $this->row('updated after the change', '2026-10-29 22:50:00', ['served_at' => '2026-10-29 23:15:00']);
        DB::table('utc_probe')->where('label', 'first pass')->update(['updated_at' => '2026-10-29 23:02:00']);

        $this->migration()->up();

        $this->assertSame([
            // updated at 23:02 after being created at 23:10: after the clock went back
            'first pass' => ['created_at' => '2026-10-29 20:10:00', 'updated_at' => '2026-10-29 21:02:00', 'served_at' => null],
            'first pass, later' => ['created_at' => '2026-10-29 20:50:00', 'updated_at' => '2026-10-29 20:50:00', 'served_at' => null],
            // created_at went back from 23:50; its other times can't come before it: the second pass too
            'clock went back' => ['created_at' => '2026-10-29 21:05:00', 'updated_at' => '2026-10-29 21:05:00', 'served_at' => '2026-10-29 21:25:00'],
            'second pass' => ['created_at' => '2026-10-29 21:40:00', 'updated_at' => '2026-10-29 21:40:00', 'served_at' => null],
            'next day' => ['created_at' => '2026-10-29 22:10:00', 'updated_at' => '2026-10-29 22:10:00', 'served_at' => null],
            // created before the repeated hour: nothing says which pass, the first one
            'updated after the change' => ['created_at' => '2026-10-29 19:50:00', 'updated_at' => '2026-10-29 19:50:00', 'served_at' => '2026-10-29 20:15:00'],
        ], $this->stored());
    }

    public function test_rolling_back_gives_the_wall_clock_times_again(): void
    {
        $times = ['2026-01-15 12:00:00', '2026-04-24 01:30:00', '2026-07-01 12:00:00', '2026-10-29 23:10:00', '2026-10-29 23:05:00', '2026-10-30 00:10:00'];
        foreach ($times as $i => $at) {
            $this->row("row $i", $at);
        }
        DB::table('password_reset_tokens')->insert(['email' => 'mona@example.com', 'token' => 'x', 'created_at' => '2026-07-01 12:00:00']);

        $this->migration()->up();
        $this->assertSame('2026-07-01 09:00:00', DB::table('password_reset_tokens')->value('created_at'));
        $this->migration()->down();
        $this->legacySession();

        $this->assertSame($times, array_column($this->stored(), 'created_at'));
        $this->assertSame('2026-07-01 12:00:00', DB::table('password_reset_tokens')->value('created_at'));
    }
}
