<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Order;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** The receipt shared after payment: a signed public read-only page on the restaurant's address, a QR on the printed receipt. */
class PosReceiptShareTest extends TenantTestCase
{
    public function test_the_public_receipt_is_signed_read_only_and_never_shows_the_guest(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($a, 'pos_pack', 1);
        $latte = $this->item($a, 'Latte', 60);
        $cashier = $this->staff($a, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $sale = $this->actingAs($cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $latte->id, 'qty' => 2]], 'pay' => 'cash',
            'customer_name' => 'Mona Private', 'customer_phone' => '01012345678',
        ])->assertOk();
        $url = $sale->json('receipt_url');
        $order = $this->as($a, fn () => Order::query()->findOrFail($sale->json('id')));
        $this->assertSame($this->pathUrl('cafe-a', 'r/'.$order->id.'/'.$this->as($a, fn () => $order->receiptSignature())), $url);
        $this->assertSame('01012345678', $sale->json('phone')); // the POS addresses the WhatsApp message

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('data-public-receipt', false)->assertSee('Latte')->assertSee('120')
            ->assertSee('<meta name="robots" content="noindex">', false)->assertDontSee('Mona Private')->assertDontSee('01012345678');
        $this->get($this->subUrl('cafe-a', 'r/'.$order->id.'/'.$this->as($a, fn () => $order->receiptSignature())))->assertOk();

        // a wrong signature, or the same link on another restaurant: not found
        $this->get($this->pathUrl('cafe-a', 'r/'.$order->id.'/'.str_repeat('0', 32)))->assertNotFound();
        $b = $this->restaurant('cafe-b', 'basic');
        $this->get(str_replace('/cafe-a/', '/cafe-b/', $url))->assertNotFound();
        $this->assertNotSame($this->as($a, fn () => $order->receiptSignature()), $this->as($b, fn () => (clone $order)->forceFill(['tenant_id' => $b->id])->receiptSignature()));

        // the printed receipt carries the QR, the POS has the WhatsApp button
        $this->actingAs($cashier)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertOk()->assertSee('data-receipt-qr', false)->assertSee('<svg', false);
        $this->actingAs($cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-share-wa', false)->assertSee('"share":true', false);
    }
}
