<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Import\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Guest list import on the customers page: upload a sheet, review new / existing / skipped rows, then save. */
class CustomerImportTest extends TenantTestCase
{
    private function csv(string $body, string $name = 'guests.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".$body);
    }

    /** Uploads the file and returns the review URL it redirects to. */
    private function upload(User $user, UploadedFile $file): string
    {
        $res = $this->actingAs($user)->post($this->dashUrl('/customers/import'), ['file' => $file]);
        $res->assertRedirect();
        $this->assertStringContainsString('/customers/import/', (string) $res->headers->get('Location'));

        return (string) $res->headers->get('Location');
    }

    private function confirm(User $user, string $reviewUrl, string $mode = 'keep'): TestResponse
    {
        return $this->actingAs($user)->post($reviewUrl, ['mode' => $mode]);
    }

    /** @return array<string,Customer> keyed by phone */
    private function customersOf(Tenant $tenant): array
    {
        return $this->as($tenant, fn () => Customer::query()->get()->keyBy('phone')->all());
    }

    public function test_csv_with_english_headers_is_reviewed_then_saved_with_normalised_mobiles(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $review = $this->upload($owner, $this->csv("Name,Mobile,Birthday,Notes,Second phone\n"
            ."Ahmed,+20 101 234 5678,1990-05-31,No sugar,0122 222 2222\n"
            ."Sara,٠١١٩٨٧٦٥٤٣٢,31/12/1995,,\n"));

        $this->assertSame([], $this->customersOf($a), 'nothing is saved before the review is confirmed');
        $this->actingAs($owner)->get($review)->assertOk()
            ->assertSee('01012345678')->assertSee('01198765432')->assertSeeInOrder([__('New customers'), '2']);

        $this->confirm($owner, $review)->assertRedirect($this->dashUrl('/customers'))->assertSessionHas('ok');

        $guests = $this->customersOf($a);
        $this->assertSame(['01012345678', '01198765432'], array_keys($guests));
        $this->assertSame('Ahmed', $guests['01012345678']->name);
        $this->assertSame('1990-05-31', $guests['01012345678']->birthday->format('Y-m-d'));
        $this->assertSame('No sugar', $guests['01012345678']->notes);
        $this->assertSame('01222222222', $guests['01012345678']->phone_2);
        $this->assertSame('import', $guests['01012345678']->source);
        $this->assertNotEmpty($guests['01012345678']->card_token);
        $this->assertSame('1995-12-31', $guests['01198765432']->birthday->format('Y-m-d'));
        $this->assertSame(0, $guests['01198765432']->visits_count);
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'customers.imported')->count()));
    }

    public function test_arabic_headers_excel_leading_zero_and_address_with_zone(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $zone = $this->as($a, fn () => DeliveryZone::query()->create(['branch_id' => Branch::query()->value('id'), 'name' => 'الدقي', 'fee' => 20]));
        $review = $this->upload($owner, $this->csv("الاسم,الموبايل,العنوان,المنطقة,موافق على العروض\n"
            ."محمد,1012345678,12 شارع التحرير,الدقي,1\n"
            ."منى,1.1987654E+9,,مدينة نصر,\n"));
        $this->confirm($owner, $review)->assertRedirect();

        $guests = $this->customersOf($a);
        $this->assertSame(['01012345678', '01198765400'], array_keys($guests));
        $this->assertSame('محمد', $guests['01012345678']->name);
        $this->assertTrue($guests['01012345678']->marketing_opt_in);
        $this->assertSame([['label' => '', 'zone_id' => $zone->id, 'text' => '12 شارع التحرير']], $guests['01012345678']->addresses);
        // an area that is not one of the delivery zones is kept as text
        $this->assertSame([['label' => '', 'zone_id' => null, 'text' => 'مدينة نصر']], $guests['01198765400']->addresses);
        $this->assertFalse($guests['01198765400']->marketing_opt_in);
    }

    public function test_xlsx_template_downloads_and_imports_back(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $res = $this->actingAs($owner)->get($this->dashUrl('/customers/import/template'))->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'cust').'.xlsx';
        (new XlsxWriter)->addSheet('Customers', [['الاسم', 'الموبايل', 'الزيارات'], ['هاني', 1005550000, 99]])->save($path);
        $review = $this->upload($owner, new UploadedFile($path, 'guests.xlsx', null, null, true));
        $this->confirm($owner, $review)->assertRedirect();

        $guest = $this->customersOf($a)['01005550000'];
        $this->assertSame('هاني', $guest->name);
        $this->assertSame(0, $guest->visits_count, 'the visits column of an export is not imported');
        @unlink($path);
    }

    public function test_bad_mobiles_duplicates_and_empty_rows_are_skipped_with_reasons(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $review = $this->upload($owner, $this->csv("Name,Mobile,Notes\n"
            ."First,01012345678,\n"
            ."Wrong,12345,\n"
            ."No phone,,\n"
            ."Again,010 1234 5678,\n"
            .",,\n"
            ."Good,01112345678,\n"));

        $this->actingAs($owner)->get($review)->assertOk()
            ->assertSee(__('Not an Egyptian mobile number'))->assertSee(__('No mobile number'))
            ->assertSee(__('Repeated in the file (line :line)', ['line' => 2]));
        $this->confirm($owner, $review)->assertSessionHas('import_summary', fn ($s) => $s['created'] === 2 && $s['skipped'] === 3);

        $guests = $this->customersOf($a);
        $this->assertSame(['01012345678', '01112345678'], array_keys($guests));
        $this->assertSame('First', $guests['01012345678']->name, 'the first row of a repeated mobile wins');
    }

    /** @return array{0: Tenant, 1: User, 2: int} restaurant, owner and the id of a guest with history */
    private function existingGuest(): array
    {
        $a = $this->restaurant('cafe-a');
        $id = $this->as($a, fn () => Customer::query()->create([
            'phone' => '01012345678', 'name' => 'Old name', 'notes' => null, 'birthday' => null,
            'visits_count' => 7, 'total_spent' => 1500, 'points_balance' => 40, 'credit_balance' => 25,
        ])->id);

        return [$a, $this->owner($a), $id];
    }

    private const UPDATE_SHEET = "Name,Mobile,Notes,Birthday,Visits,Total spent\nNew name,01012345678,VIP guest,1988-01-02,0,0\n";

    public function test_existing_guests_are_left_as_they_are_by_default(): void
    {
        [$a, $owner] = $this->existingGuest();
        $review = $this->upload($owner, $this->csv(self::UPDATE_SHEET));
        $this->actingAs($owner)->get($review)->assertOk()->assertSee(__('Leave their details as they are'));
        $this->confirm($owner, $review, 'keep')->assertSessionHas('import_summary', fn ($s) => $s['created'] === 0 && $s['updated'] === 0);

        $guest = $this->customersOf($a)['01012345678'];
        $this->assertSame('Old name', $guest->name);
        $this->assertNull($guest->notes);
    }

    public function test_fill_empty_only_keeps_filled_fields_and_never_touches_counters(): void
    {
        [$a, $owner] = $this->existingGuest();
        $this->confirm($owner, $this->upload($owner, $this->csv(self::UPDATE_SHEET)), 'fill')
            ->assertSessionHas('import_summary', fn ($s) => $s['updated'] === 1);

        $guest = $this->customersOf($a)['01012345678'];
        $this->assertSame('Old name', $guest->name);
        $this->assertSame('VIP guest', $guest->notes);
        $this->assertSame('1988-01-02', $guest->birthday->format('Y-m-d'));
        $this->assertSame([7, 1500.0, 40, 25.0], [$guest->visits_count, $guest->total_spent, $guest->points_balance, $guest->credit_balance]);
    }

    public function test_overwrite_updates_every_field_but_never_counters_or_balances(): void
    {
        [$a, $owner] = $this->existingGuest();
        $this->confirm($owner, $this->upload($owner, $this->csv(self::UPDATE_SHEET)), 'overwrite')->assertRedirect();

        $guest = $this->customersOf($a)['01012345678'];
        $this->assertSame('New name', $guest->name);
        $this->assertSame('VIP guest', $guest->notes);
        $this->assertSame([7, 1500.0, 40, 25.0], [$guest->visits_count, $guest->total_spent, $guest->points_balance, $guest->credit_balance]);
        $this->assertSame(1, $this->as($a, fn () => Customer::query()->count()));
    }

    public function test_the_same_mobile_in_another_restaurant_is_not_matched_or_changed(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->as($b, fn () => Customer::query()->create(['phone' => '01012345678', 'name' => 'B guest', 'visits_count' => 3]));
        $owner = $this->owner($a);

        $review = $this->upload($owner, $this->csv("Name,Mobile\nA guest,01012345678\n"));
        $this->actingAs($owner)->get($review)->assertOk()->assertSeeInOrder([__('New customers'), '1', __('Already on your list'), '0']);
        $this->confirm($owner, $review, 'overwrite')->assertRedirect();

        $this->assertSame('A guest', $this->customersOf($a)['01012345678']->name);
        $other = $this->customersOf($b)['01012345678'];
        $this->assertSame('B guest', $other->name);
        $this->assertSame(3, $other->visits_count);
    }

    public function test_another_restaurants_owner_cannot_confirm_a_pending_upload(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $review = $this->upload($this->owner($a), $this->csv("Name,Mobile\nA guest,01012345678\n"));

        // the review link is useless without the session that uploaded it
        $this->actingAs($this->owner($b))->post($review, ['mode' => 'keep'])->assertRedirect($this->dashUrl('/customers/import'));
        $this->assertSame([], $this->customersOf($b));
    }

    public function test_staff_without_the_customers_permission_cannot_see_or_use_the_import(): void
    {
        $a = $this->restaurant('cafe-a');
        $waiter = $this->staff($a, 'waiter', 'waiter@a.test');

        $this->actingAs($waiter)->get($this->dashUrl('/customers/import'))->assertForbidden();
        $this->actingAs($waiter)->get($this->dashUrl('/customers/import/template'))->assertForbidden();
        $this->actingAs($waiter)->post($this->dashUrl('/customers/import'), ['file' => $this->csv("Mobile\n01012345678\n")])->assertForbidden();
        $this->assertSame([], $this->customersOf($a));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers'))->assertOk()->assertSee(__('Import customers'));
    }

    public function test_only_the_owner_and_manager_import_while_the_cashier_still_sees_customers(): void
    {
        $a = $this->restaurant('cafe-a');
        $cashier = $this->staff($a, 'cashier', 'cashier@a.test');

        $this->actingAs($cashier)->get($this->dashUrl('/customers'))->assertOk()->assertDontSee('data-customers-import', false);
        $this->actingAs($cashier)->get($this->dashUrl('/customers/import'))->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/customers/import'), ['file' => $this->csv("Mobile\n01012345678\n")])->assertForbidden();

        $manager = $this->staff($a, 'manager', 'manager@a.test');
        $this->actingAs($manager)->get($this->dashUrl('/customers/import'))->assertOk();
    }

    public function test_a_file_over_2_mb_is_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $file = UploadedFile::fake()->create('guests.csv', 2049, 'text/csv');

        $this->actingAs($this->owner($a))->from($this->dashUrl('/customers/import'))->post($this->dashUrl('/customers/import'), ['file' => $file])
            ->assertRedirect($this->dashUrl('/customers/import'))->assertSessionHasErrors(['file' => __('The file is too large (max :n MB).', ['n' => 2])]);
    }

    public function test_a_file_over_5000_rows_is_refused(): void
    {
        $a = $this->restaurant('cafe-a');
        $rows = "Mobile\n";
        for ($i = 0; $i < 5001; $i++) {
            $rows .= sprintf("010%08d\n", $i);
        }

        $this->actingAs($this->owner($a))->from($this->dashUrl('/customers/import'))->post($this->dashUrl('/customers/import'), ['file' => $this->csv($rows)])
            ->assertSessionHasErrors(['file' => __('The file has more than :n rows. Split it into smaller files.', ['n' => '5,000'])]);
        $this->assertSame([], $this->customersOf($a));
    }

    public function test_a_file_without_a_mobile_column_is_refused(): void
    {
        $a = $this->restaurant('cafe-a');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/customers/import'), ['file' => $this->csv("Name,City\nAhmed,Cairo\n")])
            ->assertSessionHasErrors('file');
    }
}
