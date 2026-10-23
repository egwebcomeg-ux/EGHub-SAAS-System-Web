<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Gateways\KashierGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\PaymentConfirmed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

class OnlinePaymentsTest extends TenantTestCase
{
    private const API_KEY = 'test-payment-api-key';

    private const SECRET_KEY = 'test-secret-key';

    private const SESSIONS_URL = 'https://test-api.kashier.io/v3/payment/sessions';

    private function kashier(string $methods = 'card,wallet,valu,aman,souhoola,tru', bool $enabled = true): void
    {
        config(['services.kashier' => ['merchant_id' => 'MID-1-1', 'api_key' => self::API_KEY, 'secret_key' => self::SECRET_KEY, 'mode' => 'test', 'base_url' => null]]);
        PlatformSetting::put('gateway_kashier_enabled', $enabled ? '1' : '0');
        PlatformSetting::put('kashier_methods', $methods);
    }

    private function invoice(Tenant $tenant, int $months): Invoice
    {
        return app(Billing::class)->invoiceForPlan($tenant, Plan::query()->where('code', 'full')->firstOrFail(), $months);
    }

    /** Pays through the dashboard with Kashier faked; returns the pending attempt. */
    private function startCheckout(Tenant $tenant, Invoice $invoice, string $plan = 'now', string $sessionId = 'sess-1'): Payment
    {
        Http::preventStrayRequests();
        Http::fake([self::SESSIONS_URL => Http::response(['_id' => $sessionId, 'status' => 'CREATED',
            'sessionUrl' => "https://payments.kashier.io/session/$sessionId?mode=test"])]);
        $this->actingAs($this->owner($tenant))->post($this->dashUrl("/billing/invoices/{$invoice->number}/pay"), ['plan' => $plan])
            ->assertRedirect("https://payments.kashier.io/session/$sessionId?mode=test");

        return Payment::query()->where('invoice_id', $invoice->id)->where('gateway', 'kashier')->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    private function webhook(array $data, ?string $signature = null, array $extra = []): TestResponse
    {
        $data += ['signatureKeys' => ['amount', 'currency', 'merchantOrderId', 'method', 'status', 'transactionId']];
        $signature ??= app(KashierGateway::class)->signature($data, self::API_KEY);

        return $this->call('POST', $this->dashUrl('/billing/kashier/webhook'), [], [], [],
            ['HTTP_X_KASHIER_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['event' => 'pay', 'data' => $data] + $extra));
    }

    /** @return array<string, mixed> */
    private function success(Payment $payment, array $overrides = []): array
    {
        return $overrides + ['merchantOrderId' => $payment->merchant_ref, 'status' => 'SUCCESS', 'transactionId' => 'TX-1',
            'method' => 'valu', 'amount' => $payment->amount, 'currency' => 'EGP'];
    }

    public function test_pay_now_opens_a_kashier_session_and_records_a_pending_payment(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);

        $payment = $this->startCheckout($a, $invoice);

        $this->assertSame('pending', $payment->status);
        $this->assertSame('kashier:session:sess-1', $payment->idempotency_key);
        $this->assertFalse($payment->installments);
        Http::assertSent(fn ($request) => $request->url() === self::SESSIONS_URL
            && $request->header('Authorization')[0] === self::SECRET_KEY
            && $request->header('api-key')[0] === self::API_KEY
            && $request['order'] === $payment->merchant_ref
            && $request['amount'] === number_format($invoice->total, 2, '.', '')
            && $request['currency'] === 'EGP'
            && $request['allowedMethods'] === 'card,wallet'
            && $request['serverWebhook'] === 'http://app.egmenu.test/billing/kashier/webhook'
            && $request['merchantRedirect'] === "http://app.egmenu.test/billing/invoices/{$invoice->number}/return");
    }

    public function test_a_signed_success_webhook_activates_the_plan_exactly_once(): void
    {
        Notification::fake();
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 12);
        $payment = $this->startCheckout($a, $invoice, 'installments');

        $this->webhook($this->success($payment))->assertOk();
        $this->webhook($this->success($payment))->assertOk();      // Kashier retries: nothing more happens

        $sub = Subscription::query()->where('tenant_id', 'cafe-a')->first();
        $this->assertSame('full', $sub->plan->code);
        $this->assertSame('active', $sub->status);
        $this->assertSame(12, $sub->cycle_months);
        $this->assertSame('paid', $invoice->fresh()->status);
        $payment->refresh();
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame('TX-1', $payment->reference);
        $this->assertSame('valu', $payment->method);
        $this->assertTrue($payment->installments);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->where('status', 'succeeded')->count());
        Notification::assertSentTimes(PaymentConfirmed::class, 1);
        $this->as($a, fn () => $this->assertDatabaseHas('audit_logs', ['action' => 'billing.paid_online', 'subject' => $invoice->number]));
    }

    public function test_a_webhook_with_a_bad_signature_is_refused_and_changes_nothing(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        $payment = $this->startCheckout($a, $invoice);

        $this->webhook($this->success($payment), str_repeat('0', 64))->assertForbidden();
        // signed for one amount, then the amount changed: the signature no longer matches
        $signed = $this->success($payment) + ['signatureKeys' => ['amount', 'currency', 'merchantOrderId', 'method', 'status', 'transactionId']];
        $signature = app(KashierGateway::class)->signature($signed, self::API_KEY);
        $this->webhook(['amount' => 1] + $signed, $signature)->assertForbidden();

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_failed_transaction_marks_the_attempt_failed_and_leaves_the_invoice_open(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        $payment = $this->startCheckout($a, $invoice);

        $this->webhook($this->success($payment, ['status' => 'FAILURE', 'method' => 'card', 'transactionResponseCode' => '51']))->assertOk();

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('51', $payment->failure_reason);
        $this->assertSame('open', $invoice->fresh()->status);
        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$invoice->number}"))->assertOk()->assertSee('data-online-failed', false);
    }

    public function test_installments_are_offered_only_on_yearly_invoices_with_providers_switched_on(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $owner = $this->owner($a);

        $monthly = $this->invoice($a, 1);
        $this->actingAs($owner)->get($this->dashUrl("/billing/invoices/{$monthly->number}"))->assertOk()
            ->assertSee('data-pay-now', false)->assertDontSee('data-pay-installments', false);
        $this->actingAs($owner)->post($this->dashUrl("/billing/invoices/{$monthly->number}/pay"), ['plan' => 'installments'])
            ->assertSessionHasErrors(['online' => __('This way of paying is not available for this invoice.')]);
        $this->assertFalse(Payment::query()->where('invoice_id', $monthly->id)->exists());

        $yearly = $this->invoice($a, 12);
        $this->actingAs($owner)->get($this->dashUrl("/billing/invoices/{$yearly->number}"))->assertOk()
            ->assertSee('data-installment-provider="valu"', false)->assertSee('data-installment-provider="tru"', false);

        $this->kashier('card,wallet');
        $this->actingAs($owner)->get($this->dashUrl("/billing/invoices/{$yearly->number}"))->assertOk()
            ->assertSee('data-pay-now', false)->assertDontSee('data-pay-installments', false);
    }

    public function test_the_admin_minimum_amount_also_offers_installments_on_a_monthly_invoice(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $monthly = $this->invoice($a, 1);
        PlatformSetting::put('kashier_installments_min', (string) $monthly->total);

        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$monthly->number}"))->assertOk()
            ->assertSee('data-pay-installments', false);
    }

    public function test_the_installments_line_shows_only_when_online_payments_and_a_provider_are_live(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);

        $this->get($this->menuUrl('/pricing'))->assertOk()->assertDontSee('data-installments-promise', false);

        $this->kashier('card,wallet,valu,tru', enabled: false);
        $this->get($this->menuUrl('/pricing'))->assertOk()->assertDontSee('data-installments-promise', false);

        $this->kashier('card,wallet');
        $this->get($this->menuUrl('/pricing'))->assertOk()->assertDontSee('data-installments-promise', false);

        $this->kashier('card,wallet,valu,tru');
        config(['services.kashier.secret_key' => '']);                // keys missing in .env: nothing is promised
        $this->get($this->menuUrl('/pricing'))->assertOk()->assertDontSee('data-installments-promise', false);

        $this->kashier('card,wallet,valu,tru');
        $this->get($this->menuUrl('/pricing'))->assertOk()->assertSee('data-installments-promise', false)
            ->assertSee('ValU وTRU', false)->assertDontSee('سهولة');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/billing'))->assertOk()->assertSee('data-installments-promise', false);
    }

    public function test_a_webhook_cannot_settle_another_restaurants_invoice(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $b = $this->restaurant('cafe-b', 'pro', trial: true);
        $invoiceA = $this->invoice($a, 1);
        $invoiceB = $this->invoice($b, 1);
        $paymentA = $this->startCheckout($a, $invoiceA);

        // signed by Kashier for A's order, with B named everywhere the body lets an attacker write
        $this->webhook($this->success($paymentA), null, ['tenant' => 'cafe-b', 'invoice' => $invoiceB->number])->assertOk();
        // a reference that is not one of our attempts (B's invoice number) is acknowledged and ignored
        $this->webhook($this->success($paymentA, ['merchantOrderId' => $invoiceB->number, 'transactionId' => 'TX-2']))->assertOk();

        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('open', $invoiceB->fresh()->status);
        $this->assertSame('trialing', Subscription::query()->where('tenant_id', 'cafe-b')->value('status'));
    }

    public function test_the_return_page_rechecks_with_kashier_and_only_opens_the_own_invoice(): void
    {
        Notification::fake();
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $b = $this->restaurant('cafe-b', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        $payment = $this->startCheckout($a, $invoice);

        $this->actingAs($this->owner($b))->get($this->dashUrl("/billing/invoices/{$invoice->number}/return"))->assertNotFound();

        Http::fake([self::SESSIONS_URL.'/sess-1/payment' => Http::response(['message' => 'success',
            'data' => ['sessionId' => 'sess-1', 'status' => 'PAID', 'merchantOrderId' => 'forged', 'amount' => (string) $invoice->total, 'currency' => 'EGP', 'method' => 'card']])]);
        // the query string Kashier appends is never trusted
        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$invoice->number}/return?paymentStatus=FAILURE"))
            ->assertRedirect($this->dashUrl("/billing/invoices/{$invoice->number}"))
            ->assertSessionHas('ok', __('Payment received. Thank you! Your subscription is updated.'));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('card', $payment->fresh()->method);
        $this->webhook($this->success($payment))->assertOk();       // the later webhook does not apply it again
        Notification::assertSentTimes(PaymentConfirmed::class, 1);
    }

    public function test_a_pending_return_says_the_payment_is_being_confirmed(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        $this->startCheckout($a, $invoice);
        Http::fake([self::SESSIONS_URL.'/sess-1/payment' => Http::response(['message' => 'success', 'data' => ['status' => 'OPENED']])]);

        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$invoice->number}/return"))
            ->assertSessionHas('ok', __('We are confirming your payment with the bank. This page updates once it is done.'));
        $this->assertSame('open', $invoice->fresh()->status);
        $this->actingAs($this->owner($a))->get($this->dashUrl("/billing/invoices/{$invoice->number}"))->assertSee('data-online-processing', false);
    }

    public function test_a_gateway_error_keeps_the_restaurant_on_the_invoice(): void
    {
        $this->kashier();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        Http::preventStrayRequests();
        Http::fake([self::SESSIONS_URL => Http::response(['message' => 'Invalid merchant'], 401)]);

        $this->actingAs($this->owner($a))->post($this->dashUrl("/billing/invoices/{$invoice->number}/pay"), ['plan' => 'now'])
            ->assertSessionHasErrors(['online' => __('Online payment is unavailable right now. Try again in a few minutes or pay by transfer.')]);
        $this->assertSame('failed', Payment::query()->where('invoice_id', $invoice->id)->value('status'));
    }

    public function test_a_transfer_claim_still_works_next_to_an_unfinished_online_payment(): void
    {
        $this->kashier();
        PlatformSetting::put('pay_instapay_enabled', '1');
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $invoice = $this->invoice($a, 1);
        $online = $this->startCheckout($a, $invoice);

        $this->actingAs($this->owner($a))->post($this->dashUrl("/billing/invoices/{$invoice->number}/claim"), ['method' => 'instapay', 'reference' => 'TX-123'])
            ->assertSessionHasNoErrors();
        $admin = PlatformAdmin::query()->firstOrCreate(['email' => 'admin@egmenu.test'], ['name' => 'Admin', 'password' => 'Secret-pass-123']);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/invoices'))->assertOk()->assertSee('TX-123')->assertSee($online->merchant_ref);
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/invoices/{$invoice->id}/paid"), ['gateway' => 'instapay', 'reference' => 'TX-123', 'amount' => $invoice->total])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('instapay', Payment::query()->where('invoice_id', $invoice->id)->where('status', 'succeeded')->value('gateway'));
        $this->assertSame('pending', $online->fresh()->status);
    }

    public function test_admin_settings_show_which_kashier_keys_are_set_without_printing_them(): void
    {
        $this->kashier('card');
        config(['services.kashier.secret_key' => '']);
        $admin = PlatformAdmin::query()->firstOrCreate(['email' => 'admin@egmenu.test'], ['name' => 'Admin', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/settings'))->assertOk()
            ->assertSee('KASHIER_SECRET_KEY')->assertDontSee(self::API_KEY)->assertSee('http://app.egmenu.test/billing/kashier/webhook');

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), ['gateway_kashier_enabled' => '1', 'kashier_methods' => ['card', 'valu', 'nope']])
            ->assertSessionHasErrors('kashier_methods.2');
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), ['gateway_kashier_enabled' => '1', 'kashier_methods' => ['valu', 'card'], 'kashier_installments_min' => '5000'])
            ->assertSessionHasNoErrors();
        $this->assertSame('card,valu', PlatformSetting::get('kashier_methods'));
        $this->assertSame('5000', PlatformSetting::get('kashier_installments_min'));
    }
}
